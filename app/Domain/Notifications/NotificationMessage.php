<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

/**
 * A locale-neutral notification: translation keys plus parameters, rendered per recipient (their locale)
 * by the Notifier. Parameters are plain values and are escaped on output; `url` is a relative or
 * absolute link the recipient may open (admin or Field App). `status` overrides the event's colour and
 * icon (e.g. "danger" for a failed export of the export_ready event); `actionKey` is the translation key
 * of the link's button label (default "Open").
 */
final readonly class NotificationMessage
{
    /** @param  array<string, string|int|float|null>  $params */
    public function __construct(
        public NotificationEvent $event,
        public string $titleKey,
        public string $bodyKey,
        public array $params = [],
        public ?string $url = null,
        public ?int $organizationId = null,
        public ?string $submissionUuid = null,
        public ?string $status = null,
        public ?string $actionKey = null,
    ) {}
}
