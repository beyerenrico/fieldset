<?php

declare(strict_types=1);

namespace App\Domain\Instance\Lifecycle;

use App\Domain\Instance\Console\FsaContext;
use App\Domain\Instance\Output\CommandResult;
use Carbon\CarbonImmutable;
use SensitiveParameter;

/**
 * How a one-time sign-in link leaves fsa (KTD3, ADR 0056, ADR 0070): never in data, warnings, errors or logs.
 *
 * 1. With --secrets-file it goes into that 0600 file (the caller asked for exactly that).
 * 2. With a desktop session the shim opens it in the browser after the command exits, so an agent driving fsa
 *    never sees it.
 * 3. Otherwise it is the envelope's `secrets` entry `sign_in_link`.
 *
 * `data.sign_in_link` says which way it went and when it expires.
 */
final class SignInDelivery
{
    public const string SECRET = 'sign_in_link';

    public static function deliver(CommandResult $result, FsaContext $context, #[SensitiveParameter] string $url, ?CarbonImmutable $expiresAt, bool $toFile): CommandResult
    {
        $context->redactor->register($url);
        $info = ['expires_at' => $expiresAt?->utc()->toIso8601ZuluString()];
        if (! $toFile && $context->openInBrowser($url)) {
            return $result->withData([self::SECRET => [...$info, 'delivered' => 'browser']]);
        }

        return $result
            ->withData([self::SECRET => [...$info, 'delivered' => $toFile ? 'secrets_file' : 'secrets']])
            ->withSecret($context->secret(self::SECRET, $url, $expiresAt));
    }
}
