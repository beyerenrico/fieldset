<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Support\JsonResult;
use App\Domain\Platform\Instance\InstanceTestMail;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * The verification step of `fsa config set mail smtp` (R25, KTD25, ADR 0080): a socket-less one-off sends a test mail
 * through the instance mailer its env file configures, which apply has just pointed at the new SMTP server. Exit 0
 * when the server accepted it; the error text never carries the SMTP credentials. With --install it is the install
 * smoke test's mail (R11, ADR 0072 amendment): worded for capture mode or delivery, and `data.message_id` names its
 * Message-ID, which fsa looks up in the catcher.
 */
#[Description('Send a test mail through the instance mailer (fsa config set mail smtp)')]
#[Signature('instance:mail-test
        {--to= : Recipient (default: the longest-standing active operator)}
        {--install : The install smoke test\'s mail (its wording, and data.message_id)}
        {--json : Print one JSON document on stdout}')]
final class MailTestCommand extends Command
{
    private const string NAME = 'instance:mail-test';

    public function handle(InstanceTestMail $mail): int
    {
        $to = $this->option('to');
        $to = is_string($to) && trim($to) !== '' ? Str::lower(trim($to)) : null;

        try {
            if ($to !== null && filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
                throw new InvalidArgumentException('--to must be an e-mail address.');
            }
            $sent = $mail->send($to, $this->option('install') === true);
            $recipient = $sent['to'];
        } catch (InvalidArgumentException $e) {
            return $this->refuse('invalid_input', $e->getMessage(), 'Pass --test-to <address>.');
        } catch (RuntimeException $e) {
            return $this->refuse('test_mail_failed', 'The SMTP server did not accept the test mail: '.$e->getMessage(), 'Check host, port, encryption, user name and password.');
        }

        if ($this->option('json') === true) {
            $data = ['to' => $recipient, 'mailer' => (string) config('mail.default'), ...($this->option('install') === true ? ['message_id' => $sent['message_id']] : [])];

            return JsonResult::ok(self::NAME, $data, changed: true)->emit($this);
        }
        $this->components->info("Test mail sent to [{$recipient}].");

        return self::SUCCESS;
    }

    private function refuse(string $code, string $message, string $hint): int
    {
        if ($this->option('json') === true) {
            return JsonResult::failed(self::NAME, $code, $message, $hint)->emit($this);
        }
        $this->components->error($message);

        return self::FAILURE;
    }
}
