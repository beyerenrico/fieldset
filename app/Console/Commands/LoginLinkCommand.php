<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Support\ActorUid;
use App\Console\Support\JsonResult;
use App\Domain\Identity\Actions\IssueLoginLink;
use App\Domain\Identity\LoginLinks\IssuedLoginLink;
use App\Domain\Identity\LoginLinks\LoginLinkRefused;
use App\Domain\Identity\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The app side of `fsa login-link <email>` (R28, KTD25): runs in a one-off container without the Docker socket
 * and prints the link once. With --json the link appears only under `secrets`.
 */
#[Description('Issue a one-time sign-in link (15 minutes, single use) for an existing admin')]
#[Signature('identity:login-link
        {email : Email of an existing admin user}
        {--actor-uid= : Host UID of the operator running fsa (audit)}
        {--json : Print one JSON document on stdout}')]
final class LoginLinkCommand extends Command
{
    private const string NAME = 'identity:login-link';

    public function handle(IssueLoginLink $issue): int
    {
        try {
            $hostUid = ActorUid::from($this->option('actor-uid'));
        } catch (InvalidArgumentException $e) {
            return $this->refuse('invalid_input', $e->getMessage());
        }

        $argument = $this->argument('email');
        $email = is_string($argument) ? Str::lower(trim($argument)) : '';
        $user = User::query()->where('email', $email)->first();
        if (! $user instanceof User) {
            return $this->refuse('user_not_found', "No user with the email [{$email}].", 'Check the address, or create the account first.');
        }

        try {
            $issued = $issue->handle($user, $hostUid);
        } catch (LoginLinkRefused $e) {
            return $this->refuse($e->reason, $e->getMessage(), null, $e->reason === 'rate_limited');
        }

        return $this->option('json') === true ? $this->json($user, $issued)->emit($this) : $this->human($user, $issued);
    }

    private function json(User $user, IssuedLoginLink $issued): JsonResult
    {
        $expires = $issued->link->expires_at;

        return JsonResult::ok(self::NAME, [
            'email' => $user->email,
            'user_id' => $user->id,
            'kind' => $issued->link->kind->value,
            'link_id' => $issued->link->id,
            'expires_at' => $expires->toImmutable()->utc()->toIso8601ZuluString(),
        ], changed: true)->withSecret('sign_in_link', $issued->url, $expires);
    }

    private function human(User $user, IssuedLoginLink $issued): int
    {
        $until = $issued->link->expires_at->toImmutable()->utc()->format('Y-m-d H:i');
        $this->components->info("One-time sign-in link for [{$user->email}], single use, valid until {$until} UTC:");
        $this->line($issued->url);

        return self::SUCCESS;
    }

    private function refuse(string $code, string $message, ?string $hint = null, bool $retryable = false): int
    {
        if ($this->option('json') === true) {
            return JsonResult::failed(self::NAME, $code, $message, $hint, $retryable)->emit($this);
        }
        $this->components->error($message);

        return self::FAILURE;
    }
}
