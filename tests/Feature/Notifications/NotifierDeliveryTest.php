<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\MembershipStatus;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\Membership;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Mail\Enums\MailStatus;
use App\Domain\Notifications\Jobs\DeliverNotification;
use App\Domain\Notifications\Mail\NotificationMail;
use App\Domain\Notifications\NotificationEvent;
use App\Domain\Notifications\Notifier;
use App\Domain\Notifications\Preferences\ChannelPreferences;
use App\Domain\Submissions\Models\Submission;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Mail\MailFixtures;
use Tests\Feature\Notifications\NotificationFixtures as N;

/* M4-T06 AC (§8.9): each event reaches the right recipients on their enabled channels; preferences are respected. */

beforeEach(function (): void {
    $this->org = makeOrganization();
    $this->reviewer = memberOf($this->org, SystemRole::Reviewer);
    $this->worker = memberOf($this->org, SystemRole::FieldWorker);
    $this->notifier = app(Notifier::class);
});

it('delivers in-app and email for events that email by default', function (NotificationEvent $event): void {
    $this->notifier->send(N::message($event, $this->org), [$this->reviewer]);

    expect(N::stored($this->reviewer))->toHaveCount(1)
        ->and(N::stored($this->reviewer)->first()->data['event'])->toBe($event->value)
        ->and(N::mailRecipients())->toBe([$this->reviewer->email]);
})->with([NotificationEvent::ReviewAssigned, NotificationEvent::ChangesRequested, NotificationEvent::SubmissionApproved, NotificationEvent::WorkflowFailed]);

it('delivers informational events in-app only by default', function (NotificationEvent $event): void {
    $this->notifier->send(N::message($event, $this->org), [$this->worker]);

    expect(N::stored($this->worker))->toHaveCount(1)->and(N::mails())->toBeEmpty();
})->with([NotificationEvent::FormAvailable, NotificationEvent::WorkflowNotice]);

it('narrows delivery to the channels the caller allows', function (): void {
    $this->notifier->send(N::message(NotificationEvent::ReviewAssigned, $this->org), [$this->reviewer], [Notifier::CHANNEL_DATABASE]);
    expect(N::stored($this->reviewer))->toHaveCount(1)->and(N::mails())->toBeEmpty();

    $this->notifier->send(N::message(NotificationEvent::ReviewAssigned, $this->org), [$this->worker], [Notifier::CHANNEL_MAIL]);
    expect(N::stored($this->worker))->toBeEmpty()->and(N::mailRecipients())->toBe([$this->worker->email]);

    $this->notifier->send(N::message(NotificationEvent::ReviewAssigned, $this->org), [$this->worker], ['push']);
    $this->notifier->send(N::message(NotificationEvent::ReviewAssigned, $this->org), [$this->worker], []);
    expect(N::stored($this->worker))->toBeEmpty()->and(N::mails())->toHaveCount(1);
});

it('respects the preferences per user, event and channel', function (): void {
    $preferences = app(ChannelPreferences::class);
    $preferences->update($this->reviewer, ['review_assigned' => ['mail' => false]]);
    $preferences->update($this->worker, ['review_assigned' => ['database' => false], 'form_available' => ['mail' => true]]);

    $this->notifier->send(N::message(NotificationEvent::ReviewAssigned, $this->org), [$this->reviewer, $this->worker]);

    expect(N::stored($this->reviewer))->toHaveCount(1) // mail off → in-app only
        ->and(N::stored($this->worker))->toBeEmpty()       // in-app off → email only
        ->and(N::mailRecipients())->toBe([$this->worker->email]);

    $this->notifier->send(N::message(NotificationEvent::FormAvailable, $this->org), [$this->reviewer, $this->worker]);

    expect(N::mailRecipients())->toBe([$this->worker->email, $this->worker->email]) // opted in to form mail
        ->and(N::stored($this->worker))->toHaveCount(1)
        ->and(N::stored($this->reviewer))->toHaveCount(2);
});

it('sends to each recipient once', function (): void {
    $this->notifier->send(N::message(NotificationEvent::ReviewAssigned, $this->org), [
        $this->reviewer, $this->reviewer, User::query()->findOrFail($this->reviewer->id),
    ]);

    expect(N::stored($this->reviewer))->toHaveCount(1)->and(N::mailRecipients())->toBe([$this->reviewer->email]);
});

it('skips deactivated users and users who are no longer active members of the organization', function (): void {
    $deactivated = memberOf($this->org, SystemRole::FieldWorker);
    $deactivated->forceFill(['status' => UserStatus::Deactivated, 'deactivated_at' => now()])->save();
    $left = memberOf($this->org, SystemRole::FieldWorker);
    Membership::query()->where('user_id', $left->id)->update(['status' => MembershipStatus::Deactivated->value]);
    $outsider = memberOf(makeOrganization(name: 'Other GmbH'), SystemRole::FieldWorker);

    $this->notifier->send(N::message(NotificationEvent::ReviewAssigned, $this->org), [$deactivated, $left, $outsider, $this->worker]);

    expect(N::stored($deactivated))->toBeEmpty()
        ->and(N::stored($left))->toBeEmpty()
        ->and(N::stored($outsider))->toBeEmpty()
        ->and(N::stored($this->worker))->toHaveCount(1)
        ->and(N::mailRecipients())->toBe([$this->worker->email]);
});

it('renders title, body and mail in each recipient locale (user, else organization)', function (): void {
    $this->org->update(['locale' => 'de']);
    $english = memberOf($this->org, SystemRole::Reviewer);
    $english->update(['locale' => 'en']);

    $this->notifier->send(N::message(NotificationEvent::ReviewAssigned, $this->org, ['form' => 'Prüfbericht', 'version' => 2]), [$this->reviewer, $english]);

    expect(N::stored($this->reviewer)->first()->data['text'])->toEqual(['title' => 'Neues Formular: Prüfbericht', 'body' => '„Prüfbericht“ ist jetzt in der App verfügbar.'])
        ->and(N::stored($this->reviewer)->first()->data['locale'])->toBe('de')
        ->and(N::stored($english)->first()->data['text']['title'])->toBe('New form: Prüfbericht')
        ->and(N::stored($english)->first()->data['actions'][0]['label'])->toBe('Open')
        ->and(N::mails()->pluck('subject')->all())->toBe(['Neues Formular: Prüfbericht', 'New form: Prüfbericht']);
});

it('falls back to the app locale when neither user nor organization has a supported one', function (): void {
    $this->org->forceFill(['locale' => 'fr'])->save();

    $this->notifier->send(N::message(NotificationEvent::FormAvailable, $this->org), [$this->worker]);

    expect(N::stored($this->worker)->first()->data['locale'])->toBe(config('app.locale'));
});

it('escapes parameters on output and keeps plain text for the API', function (): void {
    $this->notifier->send(N::message(NotificationEvent::ReviewAssigned, $this->org, ['form' => '<img src=x onerror=alert(1)> & Co']), [$this->reviewer]);

    $data = N::stored($this->reviewer)->first()->data;
    $html = MailFixtures::lastSentEmail()->getHtmlBody();
    expect($data['title'])->toBe('New form: &lt;img src=x onerror=alert(1)&gt; &amp; Co')
        ->and($data['body'])->not->toContain('<img')
        ->and($data['text']['title'])->toBe('New form: <img src=x onerror=alert(1)> & Co')
        ->and($html)->not->toContain('<img src=x')->toContain('&lt;img src=x onerror=alert(1)&gt;');
});

it('stores a Filament database notification that the API can present', function (): void {
    $this->notifier->send(N::message(NotificationEvent::WorkflowFailed, $this->org, url: 'https://forms.example.test/admin/runs/5'), [$this->reviewer], [Notifier::CHANNEL_DATABASE]);

    $data = N::stored($this->reviewer)->first()->data;
    expect($data)
        ->format->toBe('filament')
        ->duration->toBe('persistent')
        ->status->toBe('danger')
        ->event->toBe('workflow_failed')
        ->url->toBe('https://forms.example.test/admin/runs/5')
        ->organization_id->toBe($this->org->id)
        ->and($data['actions'][0]['url'])->toBe('https://forms.example.test/admin/runs/5')
        ->and(Notification::fromDatabase(N::stored($this->reviewer)->first())->getTitle())->toBe('New form: Service report');
});

it('drops unsafe links', function (string $url): void {
    $this->notifier->send(N::message(NotificationEvent::ReviewAssigned, $this->org, url: $url), [$this->reviewer]);

    expect(N::stored($this->reviewer)->first()->data)->url->toBeNull()->actions->toBe([])
        ->and(MailFixtures::lastSentEmail()->getHtmlBody())->not->toContain('alert(1)')->not->toContain('evil.example');
})->with(['javascript:alert(1)', '//evil.example/x', 'data:text/html,alert(1)', ' /app x']);

it('queues a branded mail through the mail module with a button to the link', function (): void {
    $this->notifier->send(N::message(NotificationEvent::ReviewAssigned, $this->org, url: '/app/submissions/0192f1c4'), [$this->reviewer]);

    $logged = N::mails()->sole();
    $email = MailFixtures::lastSentEmail();
    expect($logged)
        ->organization_id->toBe($this->org->id)
        ->mailable_class->toBe(NotificationMail::class)
        ->status->toBe(MailStatus::Sent)
        ->subject->toBe('New form: Service report')
        ->and($email->getTo()[0]->getAddress())->toBe($this->reviewer->email)
        ->and($email->getFrom()[0]->getName())->toBe('Acme GmbH')
        ->and($email->getHtmlBody())->toContain('href="'.url('/app/submissions/0192f1c4').'"')
        ->toContain('“Service report” is now available in the app.')
        ->toContain('Hello '.e($this->reviewer->name))
        ->and($email->getTextBody())->toContain('Open: '.url('/app/submissions/0192f1c4'));
});

it('links the mail and the notification to the submission', function (): void {
    $submission = app(TenantContext::class)->run($this->org, fn (): Submission => Submission::factory()->create());

    $this->notifier->send(N::message(NotificationEvent::ChangesRequested, $this->org, submissionUuid: $submission->uuid), [$this->worker]);

    expect(N::mails()->sole()->submission_uuid)->toBe($submission->uuid)
        ->and(N::stored($this->worker)->first()->data['submission_uuid'])->toBe($submission->uuid);
});

it('never throws for one unreachable recipient', function (): void {
    Log::spy();
    $broken = memberOf($this->org, SystemRole::Reviewer);
    $broken->forceFill(['email' => 'not-an-email'])->saveQuietly();

    $this->notifier->send(N::message(NotificationEvent::ReviewAssigned, $this->org), [$broken, $this->reviewer]);

    expect(N::stored($broken))->toHaveCount(1)
        ->and(N::stored($this->reviewer))->toHaveCount(1)
        ->and(N::mailRecipients())->toBe([$this->reviewer->email]);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $message === 'Notification delivery failed'
        && $context['user_id'] === $broken->id && $context['channel'] === 'mail')->once();
});

it('delivers in-app only when no organization is known', function (): void {
    $this->notifier->send(N::message(NotificationEvent::WorkflowNotice, null), [$this->worker], null);

    expect(N::stored($this->worker))->toHaveCount(1)
        ->and(N::stored($this->worker)->first()->data['organization_id'])->toBeNull()
        ->and(N::mails())->toBeEmpty();
});

it('queues delivery after commit, in batches, and only when there is someone to notify', function (): void {
    Queue::fake();
    $users = array_map(fn (int $id): User => (new User)->forceFill(['id' => $id]), range(1, 150));

    $this->notifier->send(N::message(NotificationEvent::ReviewAssigned, $this->org), []);
    $this->notifier->send(N::message(NotificationEvent::ReviewAssigned, $this->org), [$this->worker], ['push']);
    Queue::assertNothingPushed();

    $this->notifier->send(N::message(NotificationEvent::ReviewAssigned, $this->org), [...$users, ...array_slice($users, 0, 20)], [Notifier::CHANNEL_MAIL, 'push']);

    Queue::assertPushed(DeliverNotification::class, 2);
    expect(Queue::pushed(DeliverNotification::class)->sum(fn (DeliverNotification $job): int => count($job->userIds)))->toBe(150);
    Queue::assertPushed(DeliverNotification::class, fn (DeliverNotification $job): bool => $job->afterCommit === true
        && $job->organizationId === $this->org->id && $job->channels === ['mail'] && count($job->userIds) === 100);
});

it('does not notify about work that was rolled back', function (): void {
    try {
        DB::transaction(function (): void {
            $this->notifier->send(N::message(NotificationEvent::ReviewAssigned, $this->org), [$this->worker]);

            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
    }

    expect(N::stored($this->worker))->toBeEmpty()->and(N::mails())->toBeEmpty();
});
