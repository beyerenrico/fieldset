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
 * The test mail of `fieldset config set mail smtp` (R25, ADR 0080) and of the install smoke test (R11, ADR 0072 amendment),
 * sent synchronously through the instance mailer as the one-off's env configures it: the new SMTP server, the configured
 * one, or the bundled catcher in capture mode. It counts as sent only when the server accepted it. It goes to the given
 * address, else to the longest-standing active operator (the install owner, usually). Errors reach the caller without
 * the SMTP credentials.
 *
 * The install variant says what it checks (capture mode or delivery) and carries a Message-ID of its own, which the
 * smoke test looks up in the catcher's API to see it arrive.
 */
final class InstanceTestMail
{
    /**
     * @param  bool  $install  the install smoke test's wording instead of the "new SMTP settings" one
     * @return array{to: string, message_id: string} the recipient and the Message-ID (without angle brackets)
     *
     * @throws InvalidArgumentException when there is nobody to send to
     * @throws RuntimeException when the server refuses or cannot be reached
     */
    public function send(?string $to, bool $install = false): array
    {
        $recipient = $to ?? $this->operatorAddress() ?? throw new InvalidArgumentException('No active operator to send the test mail to: pass --test-to.');
        $mailer = (string) config('mail.default');
        $messageId = bin2hex(random_bytes(16)).'@'.$this->idDomain();

        try {
            Mail::mailer($mailer)->raw($this->body($install), static function (Message $message) use ($recipient, $messageId): void {
                $message->to($recipient)->subject((string) __('capture.test_mail.subject'));
                $headers = $message->getSymfonyMessage()->getHeaders();
                $headers->remove('Message-ID');
                $headers->addIdHeader('Message-ID', $messageId);
            });
        } catch (Throwable $e) {
            $secrets = [config("mail.mailers.{$mailer}.password"), config("mail.mailers.{$mailer}.username")];

            throw new RuntimeException(ErrorText::scrub($e->getMessage(), array_values(array_filter($secrets, is_string(...)))), 0, $e);
        }

        return ['to' => $recipient, 'message_id' => $messageId];
    }

    private function body(bool $install): string
    {
        $key = match (true) {
            ! $install => 'capture.test_mail.body',
            config('mail.capture') === true => 'capture.test_mail.install_capture',
            default => 'capture.test_mail.install_smtp',
        };

        return (string) __($key, ['url' => config('app.url')]);
    }

    /** The sender's domain, as Symfony would choose for a generated Message-ID. */
    private function idDomain(): string
    {
        $from = config('mail.from.address');
        $domain = is_string($from) && str_contains($from, '@') ? substr($from, (int) strrpos($from, '@') + 1) : '';

        return preg_match('/^[A-Za-z0-9.-]+$/D', $domain) === 1 ? $domain : 'localhost';
    }

    private function operatorAddress(): ?string
    {
        $grant = PlatformAdmin::query()->with('user')->orderBy('id')->get()->first(static fn (PlatformAdmin $grant): bool => $grant->user->isActive());

        return $grant?->user->email;
    }
}
