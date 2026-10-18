<?php

declare(strict_types=1);

namespace App\Domain\Instance\Install;

use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;

/**
 * Let's Encrypt staging is for trials (ADR 0075): its certificates are not trusted by browsers. An install or a
 * `config set` that leaves domain mode on staging says so instead of succeeding silently, and names the command
 * that switches to production (ADR 0078).
 */
final class AcmeStaging
{
    public const string CODE = 'acme_staging';

    public const string SWITCH_COMMAND = 'config set acme-ca production --yes';

    public static function active(InstanceState $state): bool
    {
        return $state->mode() === 'domain' && InstanceSettings::of($state)->acmeCa() === InstanceSettings::ACME_STAGING;
    }

    /**
     * @param  string  $fsa  how to call fsa (`fsa`, or the instance's launcher path)
     * @return array{0: string, 1: string, 2: string}|null code, message, hint
     */
    public static function warning(InstanceState $state, string $fsa = 'fsa'): ?array
    {
        if (! self::active($state)) {
            return null;
        }
        $domain = InstanceSettings::of($state)->domain();

        return [
            self::CODE,
            "The certificate for {$domain} comes from Let's Encrypt staging: browsers and phones do not trust it.",
            'When the trial works, switch to production with `'.self::command($fsa).'`; the edge then orders a trusted certificate.',
        ];
    }

    public static function command(string $fsa = 'fsa'): string
    {
        return "{$fsa} ".self::SWITCH_COMMAND;
    }
}
