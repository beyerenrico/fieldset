<?php

declare(strict_types=1);

use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Mail\Models\MailMessage;
use App\Domain\Workflows\Enums\RunStatus;
use Illuminate\Support\Collection;
use Tests\Feature\Workflows\WorkflowFixtures as W;

/* M4 review fix S3: workflow emails are capped per submission and per organization (recipients may come from submitted values). */

beforeEach(function (): void {
    W::notifier();
    $this->s = W::scenario();
    $this->tenant = app(TenantContext::class);
});

function limitedMails(): Collection
{
    return app(TenantContext::class)->run(test()->s->org, fn () => MailMessage::query()->orderBy('id')->get());
}

it('links queued workflow mails to their step run', function (): void {
    W::workflow($this->s->org, [W::step('send_email', ['to_addresses' => ['office@example.com'], 'subject' => 'Neu'])]);

    W::submitted($this->s);

    expect(limitedMails()->sole()->workflow_step_run_id)->toBe(W::runs($this->s->org)->sole()->stepRuns[0]->id);
});

it('stops at the per-submission cap and fails the step with a clear message', function (): void {
    config(['workflows.email.per_submission_per_day' => 2]);
    W::workflow($this->s->org, [W::step('send_email', ['to_addresses' => ['a@example.com', 'b@example.com', 'c@example.com'], 'subject' => 'Neu'])]);

    W::submitted($this->s);

    $run = W::runs($this->s->org)->sole();
    expect(limitedMails()->pluck('recipient_email')->all())->toBe(['a@example.com', 'b@example.com'])
        ->and($run->status)->toBe(RunStatus::Failed)
        ->and($run->error)->toBe(__('workflows.errors.mail_limit_submission', ['max' => 2]))
        ->and($run->stepRuns[0]->output['mail_message_ids'])->toHaveCount(2);   // a retry later sends only the rest
});

it('stops at the per-organization hourly cap', function (): void {
    config(['workflows.email.per_organization_per_hour' => 1]);
    W::workflow($this->s->org, [W::step('send_email', ['to_addresses' => ['office@example.com'], 'subject' => 'Neu'])]);

    W::submitted($this->s);
    W::submitted($this->s);

    $runs = W::runs($this->s->org);
    expect(limitedMails())->toHaveCount(1)
        ->and($runs[0]->status)->toBe(RunStatus::Completed)
        ->and($runs[1]->status)->toBe(RunStatus::Failed)
        ->and($runs[1]->error)->toBe(__('workflows.errors.mail_limit_organization', ['max' => 1]));

    $this->travel(61)->minutes();
    W::submitted($this->s);
    expect(W::runs($this->s->org)->last()?->status)->toBe(RunStatus::Completed);
});
