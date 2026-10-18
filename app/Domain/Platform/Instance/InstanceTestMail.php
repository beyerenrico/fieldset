<?php

declare(strict_types=1);

namespace App\Domain\Platform\Instance;

use App\Domain\Audit\ErrorText;
use App\Domain\Platform\Models\PlatformAdmin;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * The test mail of `fsa config set mail smtp` (R25, ADR 0080), sent synchronously through the instance mailer as the
 * one-off's env configures it, i.e. the new SMTP server: the change counts as verified only when the server accepted
 * it. It goes to the given address, else to the longest-standing active operator (the install owner, usually).
 * Errors reach the caller without the SMTP credentials.
 */
final class InstanceTestMail
{
    /**
     * @return string the recipient
     *
     * @throws InvalidArgumentException when there is nobody to send to
     * @throws RuntimeException when the server refuses or cannot be reached
     */
    public function send(?string $to): string
    {
        $recipient = $to ?? $this->operatorAddress() ?? throw new InvalidArgumentException('No active operator to send the test mail to: pass --test-to.');
        $mailer = (string) config('mail.default');

        try {
            Mail::mailer($mailer)->raw((string) __('capture.test_mail.body', ['url' => config('app.url')]), static function (Message $message) use ($recipient): void {
                $message->to($recipient)->subject((string) __('capture.test_mail.subject'));
            });
        } catch (Throwable $e) {
            $secrets = [config("mail.mailers.{$mailer}.password"), config("mail.mailers.{$mailer}.username")];

            throw new RuntimeException(ErrorText::scrub($e->getMessage(), array_values(array_filter($secrets, is_string(...)))), 0, $e);
        }

        return $recipient;
    }

    private function operatorAddress(): ?string
    {
        $grant = PlatformAdmin::query()->with('user')->orderBy('id')->get()->first(static fn (PlatformAdmin $grant): bool => $grant->user->isActive());

        return $grant?->user->email;
    }
}
