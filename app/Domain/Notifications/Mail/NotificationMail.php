<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Mail;

use App\Domain\Mail\Mailables\BrandedMail;
use App\Domain\Mail\Templates\MailTemplate;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Support\HtmlString;

/**
 * The email channel of a notification (§8.9): organization-branded like all organization mail (§8.8),
 * sent through QueueMail (logged in mail_messages, organization sender rules). Title and body are
 * already rendered in the recipient's locale and enter the template as tokens, so they are escaped
 * like any token value; the "open" link becomes a button below the body.
 */
final class NotificationMail extends BrandedMail
{
    public function __construct(
        int $organizationId,
        string $title,
        string $body,
        public readonly ?string $actionUrl,
        string $locale,
        public readonly ?string $actionKey = null,
    ) {
        parent::__construct(
            $organizationId,
            new MailTemplate('{{ notification.title }}', ''),
            ['notification.title' => $title, 'notification.body' => $body],
            null,
            $locale,
        );
    }

    public function template(): MailTemplate
    {
        return new MailTemplate('{{ notification.title }}', (string) __('notifications.mail.body'));
    }

    public function content(): Content
    {
        $content = parent::content();
        /** @var array<string, mixed> $with */
        $with = $content->with;
        $bodyHtml = $with['bodyHtml'] ?? null;

        $with['bodyHtml'] = new HtmlString(
            ($bodyHtml instanceof Htmlable ? $bodyHtml->toHtml() : '')
            .view('mail.notification-action', [
                'url' => $this->actionUrl,
                'label' => (string) __($this->actionKey ?? 'notifications.open'),
                'layout' => $with['layout'] ?? null,
            ])->render(),
        );
        $with['bodyText'] = (is_string($with['bodyText'] ?? null) ? $with['bodyText'] : '')
            .($this->actionUrl === null ? '' : "\n\n".__($this->actionKey ?? 'notifications.open').': '.$this->actionUrl)
            ."\n\n".__('notifications.mail.preferences_hint');

        return new Content(view: 'mail.branded', text: 'mail.branded-text', with: $with);
    }
}
