<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Mail\Actions\QueueMail;
use App\Domain\Mail\Actions\SendDocuments;
use App\Domain\Mail\Actions\SendTestMail;
use App\Domain\Mail\Mailables\BrandedMail;
use App\Domain\Mail\Mailables\TestMail;
use App\Domain\Mail\Models\MailMessage;
use App\Domain\Mail\Templates\MailTemplate;
use App\Domain\Notifications\Mail\NotificationMail;
use App\Domain\Notifications\NotificationEvent;
use App\Domain\Notifications\Notifier;
use App\Domain\Reports\Saved\SaveReport;
use App\Domain\Reports\Scheduling\ScheduledReportMail;
use App\Domain\Reports\Scheduling\ScheduledReports;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Mail\MailFixtures;
use Tests\Feature\Notifications\NotificationFixtures as N;
use Tests\Feature\Reports\ReportsScenario;

/* KTD10: a stored mail restores only allow-listed classes; the list is checked against what real callers store. */

/** Marks every instantiation or destruction of a class the allow-list must keep out. */
final class HostileMailPayload
{
    public static int $created = 0;

    public function __wakeup(): void
    {
        self::$created++;
    }

    public function __destruct()
    {
        self::$created++;
    }
}

/**
 * Queues every stored mailable through its real caller with all optional parts populated.
 *
 * @return array<class-string, MailMessage>
 */
function queueEveryStoredMailable(): array
{
    $org = MailFixtures::organization();
    MailFixtures::fakeDisk();
    config(['messaging.attachment_max_bytes' => 1000, 'messaging.links.ttl_days' => 3]);
    $user = memberOf($org, SystemRole::Reviewer);

    $messages = [];
    $messages[TestMail::class] = app(SendTestMail::class)->handle($user);
    $messages[BrandedMail::class] = app(SendDocuments::class)->handle(
        new MailTemplate('Documents for {{recipient.name}}', 'See {{document.link}}'),
        'carla@example.com',
        'Carla',
        null,
        MailFixtures::document(600, 'a.pdf'),
        MailFixtures::document(600, 'b.pdf'),
    );
    app(Notifier::class)->send(N::message(NotificationEvent::ReviewAssigned, $org, url: '/app/submissions/0192f1c4'), [$user]);
    $messages[NotificationMail::class] = MailMessage::query()->where('mailable_class', NotificationMail::class)->sole();
    $messages[ScheduledReportMail::class] = queueScheduledReport();

    return $messages;
}

function queueScheduledReport(): MailMessage
{
    $s = ReportsScenario::make();
    test()->travelTo(CarbonImmutable::parse('2026-10-12 07:05', 'Europe/Berlin'));
    $s->submit($s->worker, ['amount' => '10.10', 'kind' => 'repair'], submittedAt: '2026-10-06 10:00');
    actingInAdmin($s->owner, $s->org);
    app(SaveReport::class)->handle($s->owner, null, [
        'form_id' => $s->form->id, 'name' => 'Weekly revenue', 'metric' => 'sum', 'metric_key' => 'amount',
        'visibility' => 'organization', 'schedule' => 'weekly', 'recipients' => [$s->owner->id],
    ]);
    app(TenantContext::class)->clear();
    test()->travelTo(CarbonImmutable::parse('2026-10-19 07:05', 'Europe/Berlin'));
    app(ScheduledReports::class)->sendDue();

    return MailMessage::query()->withoutTenant()->where('mailable_class', ScheduledReportMail::class)->sole();
}

/**
 * Every class named in a serialized string, enums included (a backed enum is an `E:` entry). Walks the format
 * itself: restoring the object would add classes that only a wakeup creates (Carbon's translator).
 *
 * @return list<class-string>
 */
function classesInSerialized(string $data): array
{
    $classes = [];
    $at = 0;
    $read = function () use ($data, &$at, &$read, &$classes): void {
        $type = $data[$at];
        if ($type === 'N') {
            $at += 2;

            return;
        }
        if (in_array($type, ['b', 'i', 'd', 'r', 'R'], true)) {
            $at = strpos($data, ';', $at) + 1;

            return;
        }
        preg_match('/\G.:(\d+):/', $data, $m, 0, $at);
        $length = (int) $m[1];
        $at += strlen($m[0]);
        if ($type === 's' || $type === 'S') {
            $at += $length + 3;   // "…";

            return;
        }
        if ($type === 'E') {
            $classes[] = explode(':', substr($data, $at + 1, $length))[0];
            $at += $length + 3;

            return;
        }
        $count = $length;
        if ($type === 'O') {
            $classes[] = substr($data, $at + 1, $length);
            preg_match('/\G"[^"]*":(\d+):/', $data, $c, 0, $at);
            $count = (int) $c[1];
            $at += strlen($c[0]);
        }
        $at++;   // {
        for ($i = 0; $i < $count * 2; $i++) {
            $read();
        }
        $at++;   // }
    };
    $read();

    return $classes;
}

it('restores every stored mailable queued by its real caller', function (): void {
    foreach (queueEveryStoredMailable() as $class => $message) {
        $restored = $message->fresh()->mailable();

        expect($restored)->toBeInstanceOf($class)
            ->and($restored)->toEqual(unserialize($message->payload, ['allowed_classes' => true]));
    }
});

it('keeps the allow-list equal to the classes real callers store', function (): void {
    $found = [];
    foreach (queueEveryStoredMailable() as $message) {
        $found = [...$found, ...classesInSerialized($message->payload)];
    }
    $found = array_values(array_unique($found));
    sort($found);
    $allowed = MailMessage::PAYLOAD_CLASSES;
    sort($allowed);

    expect($found)->toBe($allowed)
        ->and(array_filter($allowed, static fn (string $c): bool => ! class_exists($c) && ! enum_exists($c)))->toBe([])
        ->and(array_diff(MailMessage::MAILABLE_CLASSES, MailMessage::PAYLOAD_CLASSES))->toBe([]);
});

it('stores a mailable class from the allow-list for every caller', function (): void {
    foreach (queueEveryStoredMailable() as $class => $message) {
        expect(MailMessage::MAILABLE_CLASSES)->toContain($message->mailable_class)->and($message->mailable_class)->toBe($class);
    }
});

it('refuses a payload whose graph holds a class outside the list without creating it', function (): void {
    $org = MailFixtures::organization();
    $mail = new TestMail($org->id);
    $mail->viewData['hostile'] = [new HostileMailPayload];
    HostileMailPayload::$created = 0;
    Log::spy();
    $message = MailMessage::factory()->create(['payload' => serialize($mail)]);
    HostileMailPayload::$created = 0;

    expect(fn () => $message->fresh()->mailable())->toThrow(UnexpectedValueException::class, 'does not hold a mailable')
        ->and(HostileMailPayload::$created)->toBe(0);
    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $m, array $context): bool => ($context['class'] ?? null) === HostileMailPayload::class && ($context['mail_message_id'] ?? null) === $message->id,
    )->once();
});

it('refuses a top-level object outside the list without creating it', function (): void {
    MailFixtures::organization();
    $message = MailMessage::factory()->create(['payload' => serialize(new HostileMailPayload)]);
    HostileMailPayload::$created = 0;

    expect(fn () => $message->fresh()->mailable())->toThrow(UnexpectedValueException::class, 'does not hold a mailable')
        ->and(HostileMailPayload::$created)->toBe(0);
});

it('refuses a row whose mailable class is not on the list', function (): void {
    $org = MailFixtures::organization();
    $message = MailMessage::factory()->create(['mailable_class' => HostileMailPayload::class, 'payload' => serialize(new TestMail($org->id))]);

    expect(fn () => $message->fresh()->mailable())->toThrow(UnexpectedValueException::class, 'does not hold a mailable');
});

it('refuses a payload that is not serialized data', function (): void {
    MailFixtures::organization();
    $message = MailMessage::factory()->create(['payload' => 'not a payload']);

    expect(fn () => @$message->fresh()->mailable())->toThrow(UnexpectedValueException::class, 'does not hold a mailable');
});

it('queues through QueueMail only mailables of the list', function (): void {
    Queue::fake();
    $org = MailFixtures::organization();
    $message = app(QueueMail::class)->handle(new TestMail($org->id), 'ann@example.com');

    expect(MailMessage::MAILABLE_CLASSES)->toContain($message->mailable_class);
});
