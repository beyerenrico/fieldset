<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Support\JsonResult;
use App\Domain\Platform\Instance\CapturedMail;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * What capture mode kept from people, for the result of `fsa config set mail smtp` (ADR 0080): a socket-less one-off
 * counts the invitations still waiting and the password resets still valid. Read-only.
 */
#[Description('Count invitations and password resets that capture mode kept in the catcher (fsa config set mail)')]
#[Signature('instance:mail-captured
        {--json : Print one JSON document on stdout}')]
final class MailCapturedCommand extends Command
{
    public function handle(CapturedMail $captured): int
    {
        $counts = $captured->assess();
        if ($this->option('json') === true) {
            return JsonResult::ok('instance:mail-captured', $counts)->emit($this);
        }
        $this->components->info("{$counts['invitations']} pending invitation(s) and {$counts['password_resets']} open password reset(s) went to the catcher.");

        return self::SUCCESS;
    }
}
