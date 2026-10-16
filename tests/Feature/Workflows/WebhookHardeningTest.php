<?php

declare(strict_types=1);

use App\Domain\Audit\Models\Activity;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Notifications\NotificationEvent;
use App\Domain\Workflows\Actions\SaveWebhookEndpoint;
use App\Domain\Workflows\Engine\RunWorkflowStep;
use App\Domain\Workflows\Engine\StepFailedException;
use App\Domain\Workflows\Engine\WorkflowRunner;
use App\Domain\Workflows\Enums\RunStatus;
use App\Domain\Workflows\Enums\StepStatus;
use App\Domain\Workflows\Models\WebhookEndpoint;
use App\Domain\Workflows\Models\WorkflowRun;
use App\Domain\Workflows\Webhooks\CappedStream;
use App\Domain\Workflows\Webhooks\WebhookTarget;
use App\Domain\Workflows\Webhooks\WebhookUrl;
use App\Filament\Admin\Resources\Workflows\WebhookEndpoints\Pages\ManageWebhookEndpoints;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Feature\Workflows\FakeHostResolver;
use Tests\Feature\Workflows\WorkflowFixtures as W;

/* M4 review fixes S5 (no duplicate notify/webhook on a re-claim) and S7 (masked URLs, ports, capped response body). */

beforeEach(function (): void {
    $this->notifier = W::notifier();
    $this->s = W::scenario();
    $this->tenant = app(TenantContext::class);
    $this->resolver = FakeHostResolver::bind();
});

/** Puts step 0 of the run back to "running" as if its worker died before recording the result. */
function hardeningReclaim(WorkflowRun $run, array $output): void
{
    app(TenantContext::class)->run(test()->s->org, function () use ($run, $output): void {
        $run->forceFill(['status' => RunStatus::Running, 'current_step' => 0, 'finished_at' => null])->save();
        $run->stepRuns()->where('index', 0)->update(['status' => StepStatus::Running->value, 'output' => json_encode($output)]);
    });
    app()->call([new RunWorkflowStep($run->id, 0, test()->s->org->id), 'handle']);
}

it('does not notify twice when a notify step is claimed again after it already sent', function (): void {
    W::workflow($this->s->org, [W::step('notify', ['user_ids' => [$this->s->owner->id], 'channels' => ['database'], 'message' => 'x'])]);
    W::submitted($this->s);
    $run = W::runs($this->s->org)->sole();
    expect($run->stepRuns[0]->output)->toHaveKey('notified_at');

    hardeningReclaim($run, $run->stepRuns[0]->output);

    expect($this->notifier->sentFor(NotificationEvent::WorkflowNotice))->toHaveCount(1)
        ->and(W::runs($this->s->org)->sole()->status)->toBe(RunStatus::Completed);
});

it('does not POST again after an interrupted delivery with unknown outcome; a manual retry sends it', function (): void {
    Http::fake(['hooks.example.com/*' => Http::response('ok', 200)]);
    $endpoint = $this->tenant->run($this->s->org, fn () => WebhookEndpoint::factory()->create(['url' => 'https://hooks.example.com/in']));
    W::workflow($this->s->org, [W::step('webhook', ['endpoint_id' => $endpoint->id])]);
    W::submitted($this->s);
    Http::assertSentCount(1);
    $run = W::runs($this->s->org)->sole();

    hardeningReclaim($run, ['in_flight' => ['attempt' => 1, 'at' => now()->toIso8601String()]]);

    Http::assertSentCount(1);
    $run = W::runs($this->s->org)->sole();
    expect($run->status)->toBe(RunStatus::Failed)->and($run->error)->toBe(__('workflows.errors.webhook_interrupted'));

    $this->tenant->run($this->s->org, fn () => app(WorkflowRunner::class)->retry($run, $this->s->owner));

    Http::assertSentCount(2);
    expect(W::runs($this->s->org)->sole()->status)->toBe(RunStatus::Completed);
});

it('drops overlapping step jobs instead of releasing them, so they never use up tries', function (): void {
    $middleware = new RunWorkflowStep(1, 0, 1)->middleware()[0];

    expect($middleware->releaseAfter)->toBeNull();
});

it('masks secrets in webhook URLs', function (string $url, string $masked): void {
    expect(WebhookUrl::mask($url))->toBe($masked);
})->with([
    'slack style' => ['https://hooks.slack.com/services/T0001/B0002/abcdefSECRET', 'https://hooks.slack.com/services/•••/•••/•••'],
    'query token' => ['https://crm.example.com/hook?token=abc123&mode=full', 'https://crm.example.com/hook?token=•••&mode=•••'],
    'port kept' => ['http://erp.example.com:8080/api/in', 'http://erp.example.com:8080/api/in'],
    'long word' => ['https://example.com/averyveryverylongsegmentname', 'https://example.com/•••'],
]);

it('shows masked URLs in the endpoint table and the audit log', function (): void {
    actingInAdmin($this->s->owner, $this->s->org);
    $endpoint = app(SaveWebhookEndpoint::class)->handle(null, ['name' => 'CRM', 'url' => 'https://crm.example.com/hook?token=topsecret42'], $this->s->owner);
    app(SaveWebhookEndpoint::class)->handle($endpoint, ['name' => 'CRM', 'url' => 'https://crm.example.com/hook?token=evenmoresecret'], $this->s->owner);

    Livewire::test(ManageWebhookEndpoints::class)->assertOk()
        ->assertSee('https://crm.example.com/hook?token=•••')->assertDontSee('evenmoresecret');
    $audit = json_encode(Activity::query()->where('subject_type', $endpoint->getMorphClass())->where('subject_id', $endpoint->id)->get()->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    expect($audit)->not->toContain('topsecret42')->not->toContain('evenmoresecret')->toContain('https://crm.example.com/hook?token=•••');
});

it('allows only the web ports 80, 443, 8080 and 8443 unless private hosts are allowed', function (): void {
    expect(WebhookTarget::syntaxProblem('https://hooks.example.com:8443/in'))->toBeNull()
        ->and(WebhookTarget::syntaxProblem('http://hooks.example.com:8080/in'))->toBeNull()
        ->and(WebhookTarget::syntaxProblem('https://hooks.example.com:22/in'))->toBe(__('workflows.errors.webhook_port', ['port' => 22]))
        ->and(fn () => WebhookTarget::resolve('https://hooks.example.com:6379/in', $this->resolver))->toThrow(StepFailedException::class);

    config(['workflows.webhooks.allow_private_hosts' => true]);
    expect(WebhookTarget::syntaxProblem('http://localhost:3000/in'))->toBeNull();
});

it('keeps at most 64 KB of a webhook response body', function (): void {
    $stream = new CappedStream(Utils::streamFor(''), 64 * 1024);

    expect($stream->write(str_repeat('a', 40 * 1024)))->toBe(40 * 1024)
        ->and($stream->write(str_repeat('b', 40 * 1024)))->toBe(40 * 1024)   // accepted, so the transfer goes on
        ->and($stream->getSize())->toBe(64 * 1024);
});
