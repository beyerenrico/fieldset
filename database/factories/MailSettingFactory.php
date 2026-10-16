<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Mail\Enums\SmtpEncryption;
use App\Domain\Mail\Models\MailSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MailSetting>
 */
class MailSettingFactory extends Factory
{
    protected $model = MailSetting::class;

    public function definition(): array
    {
        return [
            'sender_name' => fake()->company(),
            'reply_to_address' => fake()->safeEmail(),
            'smtp_enabled' => false,
        ];
    }

    public function withSmtp(string $host = 'smtp.example.com', int $port = 587, SmtpEncryption $encryption = SmtpEncryption::StartTls): static
    {
        return $this->state(fn (): array => [
            'smtp_enabled' => true,
            'smtp_host' => $host,
            'smtp_port' => $port,
            'smtp_encryption' => $encryption,
            'smtp_username' => 'mailer',
            'smtp_password' => 'smtp-secret',
            'smtp_from_address' => 'office@example.com',
        ]);
    }
}
