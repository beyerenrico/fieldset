<?php

declare(strict_types=1);

namespace App\Domain\Mail\Enums;

/**
 * Transport security of a per-organization SMTP server. STARTTLS is required (not opportunistic)
 * when chosen; "none" exists for development relays such as Mailpit.
 */
enum SmtpEncryption: string
{
    case StartTls = 'starttls';
    case Tls = 'tls';
    case None = 'none';

    public function label(): string
    {
        return __('mail.settings.encryption_options.'.$this->value);
    }

    public function scheme(): string
    {
        return $this === self::Tls ? 'smtps' : 'smtp';
    }

    public function defaultPort(): int
    {
        return match ($this) {
            self::StartTls => 587,
            self::Tls => 465,
            self::None => 25,
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
