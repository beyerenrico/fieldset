<?php

declare(strict_types=1);

namespace App\Domain\Mail\Enums;

/**
 * Delivery state of a mail_messages row (§8.8): queued until a worker claims it (sending), sent once the
 * transport accepted it, failed after the last attempt or when an attempt was interrupted mid-send.
 * Failed mail can be retried, which puts it back to queued.
 */
enum MailStatus: string
{
    case Queued = 'queued';
    case Sending = 'sending';
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
            self::Sending => 'info',
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
