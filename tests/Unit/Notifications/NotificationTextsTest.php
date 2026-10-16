<?php

declare(strict_types=1);

use App\Domain\Notifications\Delivery\NotificationRenderer;
use App\Domain\Notifications\NotificationEvent;
use App\Domain\Notifications\Notifier;
use App\Domain\Notifications\Preferences\ChannelPreferences;
use Illuminate\Support\Arr;

/** @return list<string> */
function notificationTranslationKeys(string $locale): array
{
    $keys = array_keys(Arr::dot(require lang_path("{$locale}/notifications.php")));
    sort($keys);

    return $keys;
}

it('has the same notification texts in German and English, with a label for every event and channel', function (): void {
    $english = notificationTranslationKeys('en');

    expect(notificationTranslationKeys('de'))->toBe($english);
    foreach (NotificationEvent::cases() as $event) {
        expect($english)->toContain("events.{$event->value}");
    }
    foreach (ChannelPreferences::CHANNELS as $channel) {
        expect($english)->toContain("channels.{$channel}");
    }
});

it('emails by default only for events where someone has to act or learns an outcome', function (): void {
    $mailByDefault = array_values(array_filter(
        NotificationEvent::cases(),
        fn (NotificationEvent $event): bool => ChannelPreferences::default($event, Notifier::CHANNEL_MAIL),
    ));

    expect($mailByDefault)->toBe([
        NotificationEvent::ReviewAssigned, NotificationEvent::ChangesRequested,
        NotificationEvent::SubmissionApproved, NotificationEvent::WorkflowFailed,
        NotificationEvent::ExportReady,
    ]);
    foreach (NotificationEvent::cases() as $event) {
        expect(ChannelPreferences::default($event, Notifier::CHANNEL_DATABASE))->toBeTrue()
            ->and(ChannelPreferences::default($event, 'push'))->toBeFalse();
    }
});

it('keeps only same-site paths and http(s) links', function (?string $url, ?string $expected): void {
    expect(NotificationRenderer::safeUrl($url))->toBe($expected);
})->with([
    'field app path' => ['/app/submissions/0192f1c4', '/app/submissions/0192f1c4'],
    'admin url' => ['https://forms.example.test/admin/acme/tasks', 'https://forms.example.test/admin/acme/tasks'],
    'trimmed' => ['  /app/forms ', '/app/forms'],
    'none' => [null, null],
    'empty' => ['', null],
    'javascript' => ['javascript:alert(1)', null],
    'protocol relative' => ['//evil.example/x', null],
    'backslash host' => ['/\\evil.example', null],
    'data' => ['data:text/html,x', null],
    'mailto' => ['mailto:a@example.com', null],
    'control characters' => ["/app\nx", null],
    'relative without slash' => ['app/forms', null],
]);
