<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Notifications\FakeNotifier;
use App\Domain\Notifications\NotificationEvent;
use App\Domain\Notifications\NotificationMessage;
use App\Domain\Notifications\Notifier;
use App\Domain\Notifications\NullNotifier;

it('binds a null notifier until the channels are wired', function (): void {
    expect(app(Notifier::class))->toBeInstanceOf(NullNotifier::class);
});

it('records recipients per event in the fake', function (): void {
    $fake = new FakeNotifier;
    $a = (new User)->forceFill(['id' => 1]);
    $b = (new User)->forceFill(['id' => 2]);

    $fake->send(new NotificationMessage(NotificationEvent::ReviewAssigned, 't', 'b'), [$a, $b], [Notifier::CHANNEL_MAIL]);
    $fake->send(new NotificationMessage(NotificationEvent::ReviewAssigned, 't', 'b'), [$a]);

    expect($fake->recipientsOf(NotificationEvent::ReviewAssigned))->toBe([1, 2])
        ->and($fake->hasSentTo($b, NotificationEvent::ReviewAssigned))->toBeTrue()
        ->and($fake->hasSentTo($b, NotificationEvent::SubmissionApproved))->toBeFalse()
        ->and($fake->sentFor(NotificationEvent::ReviewAssigned)[0]['channels'])->toBe(['mail']);
});
