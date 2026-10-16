<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Mail\Enums\MailStatus;
use App\Domain\Mail\Mailables\TestMail;
use App\Domain\Mail\Models\MailMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MailMessage>
 */
class MailMessageFactory extends Factory
{
    protected $model = MailMessage::class;

    public function definition(): array
    {
        return [
            'recipient_email' => fake()->safeEmail(),
            'recipient_name' => fake()->name(),
            'subject' => fake()->sentence(),
            'status' => MailStatus::Queued,
            'mailable_class' => TestMail::class,
            'payload' => fn (array $attrs): string => serialize(
                (new TestMail(app(TenantContext::class)->require()))->to($attrs['recipient_email'])
            ),
            'queued_at' => now(),
        ];
    }

    public function failed(string $error = 'Connection refused'): static
    {
        return $this->state(fn (): array => ['status' => MailStatus::Failed])
            ->afterCreating(fn (MailMessage $message) => $message->markFailed($error));
    }
}
