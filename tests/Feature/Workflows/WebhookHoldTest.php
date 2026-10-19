<?php

declare(strict_types=1);

use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Instance\Runtime\RuntimeStateReader;
use App\Domain\Workflows\Engine\RunWorkflowStep;
use App\Domain\Workflows\Enums\RunStatus;
use App\Domain\Workflows\Enums\StepStatus;
use App\Domain\Workflows\Models\WebhookEndpoint;
use App\Domain\Workflows\Models\WorkflowStepRun;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Workflows\FakeHostResolver;
use Tests\Feature\Workflows\WorkflowFixtures as W;
use Tests\Support\Instance\RuntimeFixture;

/*
 * Paused webhooks after a move (plan U17, ADR 0085): `fsa install --from-backup` starts the instance with
 * `webhooks.paused` in runtime.json until the operator confirms with `fsa config set webhooks on`. Meanwhile a webhook
 * step is not claimed: nothing is sent, the step stays pending, no attempt is counted, and the stalled-run sweep queues it
 * again later; once the flag is off the step is delivered. Other step types are not held.
 */

beforeEach(function (): void {
    W::notifier();
    $this->s = W::scenario();
    $this->runtime = RuntimeFixture::install(['webhooks' => ['paused' => true]]);
    Http::fake(['hooks.example.com/*' => Http::response(['ok' => true], 202)]);
    FakeHostResolver::bind(['hooks.example.com' => ['93.184.215.14']]);
});

afterEach(function (): void {
    RuntimeFixture::cleanup();
});

function heldStep(object $test, int $runId, int $index): WorkflowStepRun
{
    return app(TenantContext::class)->run($test->s->org, fn () => WorkflowStepRun::query()->where('workflow_run_id', $runId)->where('index', $index)->sole());
}

function heldEndpoint(object $test): WebhookEndpoint
{
    return app(TenantContext::class)->run($test->s->org, fn () => WebhookEndpoint::factory()->create(['url' => 'https://hooks.example.com/in', 'secret' => 'hold-secret']));
}

it('holds a webhook step while webhooks are paused and delivers it once the operator confirmed the move', function (): void {
    $endpoint = heldEndpoint($this);
    W::workflow($this->s->org, [W::step('webhook', ['endpoint_id' => $endpoint->id])]);

    W::submitted($this->s);
    $run = W::runs($this->s->org)->sole();
    $step = heldStep($this, $run->id, 0);

    Http::assertNothingSent();
    expect($run->status)->not->toBe(RunStatus::Failed)
        ->and($run->status->isFinished())->toBeFalse()
        ->and($step->status)->toBe(StepStatus::Pending)
        ->and($step->attempts)->toBe(0);

    RuntimeFixture::write($this->runtime, RuntimeFixture::document(['webhooks' => ['paused' => false]]));
    Bus::dispatch(new RunWorkflowStep($run->id, 0, $this->s->org->id)); // what the stalled-run sweep does later

    Http::assertSentCount(1);
    expect(W::runs($this->s->org)->sole()->status)->toBe(RunStatus::Completed);
});

it('keeps holding when the paused flag is still set and does not hold other step types', function (): void {
    $endpoint = heldEndpoint($this);
    W::workflow($this->s->org, [W::step('set_status', ['status' => 'completed']), W::step('webhook', ['endpoint_id' => $endpoint->id])]);

    W::submitted($this->s);
    $run = W::runs($this->s->org)->sole();
    Bus::dispatch(new RunWorkflowStep($run->id, 1, $this->s->org->id));

    Http::assertNothingSent();
    expect(heldStep($this, $run->id, 0)->status)->toBe(StepStatus::Completed)
        ->and(heldStep($this, $run->id, 1)->status)->toBe(StepStatus::Pending)
        ->and(W::runs($this->s->org)->sole()->current_step)->toBe(1);
});

it('delivers at once without a runtime directory (checkout installs) or without the flag', function (?array $runtime): void {
    if ($runtime === null) {
        config(['instance.runtime_path' => null]);
    } else {
        RuntimeFixture::write($this->runtime, RuntimeFixture::document($runtime));
    }
    $endpoint = heldEndpoint($this);
    W::workflow($this->s->org, [W::step('webhook', ['endpoint_id' => $endpoint->id])]);

    W::submitted($this->s);

    Http::assertSentCount(1);
    expect(W::runs($this->s->org)->sole()->status)->toBe(RunStatus::Completed);
})->with([
    'no runtime directory' => [null],
    'flag missing' => [[]],
]);

it('holds when runtime.json is unusable and this process never read it (fail closed), else follows the last good value (review S5)', function (?bool $lastGoodPaused, bool $held): void {
    $endpoint = heldEndpoint($this);
    W::workflow($this->s->org, [W::step('webhook', ['endpoint_id' => $endpoint->id])]);
    W::submitted($this->s);
    $run = W::runs($this->s->org)->sole();
    app()->forgetInstance(RuntimeStateReader::class); // a worker that starts now
    if ($lastGoodPaused !== null) {
        RuntimeFixture::write($this->runtime, RuntimeFixture::document(['webhooks' => ['paused' => $lastGoodPaused]]));
        app(RuntimeStateReader::class)->reading();
    }
    RuntimeFixture::write($this->runtime, '{"schema_version": "1.0", "instance_url": '); // a torn or broken file

    Bus::dispatch(new RunWorkflowStep($run->id, 0, $this->s->org->id));

    expect(app(RuntimeStateReader::class)->reading()->problem)->not->toBeNull();
    if ($held) {
        Http::assertNothingSent();
        expect(heldStep($this, $run->id, 0)->status)->toBe(StepStatus::Pending)->and(heldStep($this, $run->id, 0)->attempts)->toBe(0);
    } else {
        Http::assertSentCount(1);
        expect(W::runs($this->s->org)->sole()->status)->toBe(RunStatus::Completed);
    }
})->with([
    'never read: fallback' => [null, true],
    'last good value paused' => [true, true],
    'last good value not paused' => [false, false],
]);
