<?php

declare(strict_types=1);

namespace App\Domain\Mail\Enums;

/**
 * Delivery state of a mail_messages row (§8.8): queued until the transport accepted the message,
 * failed after the last attempt. Failed mail can be retried, which puts it back to queued.
 */
enum MailStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';

    public function label(): string
    {
        return __('mail.status.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Queued => 'warning',
            self::Sent => 'success',
            self::Failed => 'danger',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
