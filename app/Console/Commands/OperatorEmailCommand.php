<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Support\ActorUid;
use App\Console\Support\JsonResult;
use App\Domain\Identity\LoginLinks\IssuedLoginLink;
use App\Domain\Identity\LoginLinks\LoginLinkRefused;
use App\Domain\Platform\Instance\OperatorEmailRefused;
use App\Domain\Platform\Instance\OperatorEmailResult;
use App\Domain\Platform\Instance\SetOperatorEmail;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The app side of `fsa config set operator-email <email>` (R32, KTD25, ADR 0080): a socket-less one-off grants the
 * operator role (creating the account when needed), issues the first sign-in link when the account has no password
 * yet, and with --revoke-previous revokes every other operator. With --json the link appears only under `secrets`.
 */
#[Description('Make an address a platform operator (fsa config set operator-email)')]
#[Signature('instance:operator-email
        {--email= : The new operator\'s address}
        {--revoke-previous : Revoke the operator role of everyone else}
        {--actor-uid= : Host UID of the operator running fsa (audit)}
        {--json : Print one JSON document on stdout}')]
final class OperatorEmailCommand extends Command
{
    private const string NAME = 'instance:operator-email';

    public function handle(SetOperatorEmail $set): int
    {
        try {
            $uid = ActorUid::from($this->option('actor-uid'));
            $email = $this->email();
            $result = $set->handle($email, $this->option('revoke-previous') === true, $uid);
        } catch (InvalidArgumentException $e) {
            return $this->refuse('invalid_input', $e->getMessage());
        } catch (OperatorEmailRefused $e) {
            return $this->refuse($e->reason, $e->getMessage(), $e->hint);
        } catch (LoginLinkRefused $e) {
            return $this->refuse($e->reason, $e->getMessage(), null, $e->reason === 'rate_limited');
        }

        return $this->option('json') === true ? $this->json($result)->emit($this) : $this->human($result);
    }

    /** @throws InvalidArgumentException */
    private function email(): string
    {
        $email = $this->option('email');
        $email = is_string($email) ? Str::lower(trim($email)) : '';
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('--email must be an e-mail address.');
        }

        return $email;
    }

    private function json(OperatorEmailResult $result): JsonResult
    {
        $json = JsonResult::ok(self::NAME, [
            'email' => $result->user->email, 'user_id' => $result->user->id, 'created' => $result->created,
            'granted' => $result->granted, 'revoked' => $result->revoked, 'password_set' => $result->user->password_known,
            'login_url' => url('/platform/login'),
        ], changed: $result->changed());

        return $result->link instanceof IssuedLoginLink ? $json->withSecret('sign_in_link', $result->link->url, $result->link->link->expires_at) : $json;
    }

    private function human(OperatorEmailResult $result): int
    {
        $this->components->info("[{$result->user->email}] is a platform operator".($result->granted ? ' now.' : ' (already).'));
        foreach ($result->revoked as $email) {
            $this->line("  revoked: {$email}");
        }
        if ($result->link instanceof IssuedLoginLink) {
            $this->line($result->link->url);
        }

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
