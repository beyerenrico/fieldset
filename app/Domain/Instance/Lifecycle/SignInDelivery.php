<?php

declare(strict_types=1);

namespace App\Domain\Instance\Lifecycle;

use App\Domain\Instance\Console\FsaContext;
use App\Domain\Instance\Output\CommandResult;
use Carbon\CarbonImmutable;
use SensitiveParameter;

/**
 * How a one-time sign-in link leaves fsa (KTD3, R12, ADR 0056, ADR 0070): never in data, warnings, errors or logs.
 *
 * 1. With --secrets-file it goes into that 0600 file (the caller asked for exactly that).
 * 2. With --open, or for a person at a desktop terminal (no --json, a TTY, a desktop session), the shim opens it
 *    in the browser after the command exits; `data.sign_in_link.hint` names `fsa login-link` as the fallback.
 * 3. Otherwise it is the envelope's `secrets` entry `sign_in_link` ("as JSON", R12).
 *
 * `data.sign_in_link` says which way it went and when it expires.
 */
final class SignInDelivery
{
    public const string SECRET = 'sign_in_link';

    public static function deliver(CommandResult $result, FsaContext $context, #[SensitiveParameter] string $url, ?CarbonImmutable $expiresAt, bool $toFile, string $email): CommandResult
    {
        $context->redactor->register($url);
        $info = ['expires_at' => $expiresAt?->utc()->toIso8601ZuluString()];
        if (! $toFile && $context->handOverAllowed() && $context->openInBrowser($url)) {
            return $result->withData([self::SECRET => [...$info, 'delivered' => 'browser', 'hint' => 'If no browser opened, run '.self::loginLinkCommand($email).'.']]);
        }

        return $result
            ->withData([self::SECRET => [...$info, 'delivered' => $toFile ? 'secrets_file' : 'secrets']])
            ->withSecret($context->secret(self::SECRET, $url, $expiresAt));
    }

    /** The command that issues a new link into a file: the fallback whenever a link did not reach its reader. */
    public static function loginLinkCommand(?string $email): string
    {
        return '`fsa login-link '.($email === null || $email === '' ? '<email>' : $email).' --secrets-file <file>`';
    }
}
