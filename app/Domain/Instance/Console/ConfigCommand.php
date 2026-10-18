<?php

declare(strict_types=1);

namespace App\Domain\Instance\Console;

use App\Domain\Instance\Config\ConfigKeys;
use App\Domain\Instance\Config\ConfigRequest;
use App\Domain\Instance\Config\ConfigSetter;
use App\Domain\Instance\Config\ConfigShow;
use App\Domain\Instance\Install\AcmeCaRoot;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\State\InstanceSettings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;

/**
 * `fsa config show` and `fsa config set <key> <value>` (§7, R31, R32, R54, ADR 0077). `show` prints the effective
 * settings (secrets redacted) and what each opt-in feature contacts. `set` changes one setting under the instance
 * lock and journal: validate (exit 2), confirm a URL change (exit 6 without --yes or a terminal), preflight (exit 3),
 * apply, verify, and revert on failure (exit 5 `rolled_back`, `reverted: true`, ADR 0078; exit 8 when the revert fails too and the instance is
 * stopped). The same value again is `changed: false` without touching Docker. Mail settings arrive with M11
 * (2.0.0-beta.1): `config set mail` exits 2 `setting_not_available`, with or without a value.
 */
#[Description('Show the settings (secrets redacted) or change one: fsa config show | fsa config set <key> <value>')]
#[Signature('fsa:config
        {action : show, or set}
        {key? : For set: domain, url, port, trusted-proxy, mode, acme-email or acme-ca}
        {value? : For set: the new value}
        {--acme-email= : config set domain: contact address for the ACME account}
        {--acme-staging : config set domain: Let\'s Encrypt staging (untrusted certificates; for trials)}
        {--acme-ca= : config set domain: another ACME directory URL}
        {--acme-ca-root-file= : config set domain or acme-ca: PEM root of a private ACME directory\'s HTTPS certificate}')]
final class ConfigCommand extends FsaCommand
{
    protected function perform(FsaContext $context): CommandResult
    {
        return match ($this->argument('action')) {
            'show' => $this->show(),
            'set' => $this->set($context),
            default => throw InvalidInput::usage('Use `fsa config show` or `fsa config set <key> <value>`.'),
        };
    }

    private function show(): CommandResult
    {
        if ($this->argument('key') !== null || $this->argument('value') !== null) {
            throw InvalidInput::usage('`fsa config show` takes no further arguments.');
        }

        return $this->laravel->make(ConfigShow::class)->build();
    }

    private function set(FsaContext $context): CommandResult
    {
        $key = $this->argument('key');
        $value = $this->argument('value');
        if (is_string($key) && trim($key) !== '') {
            ConfigKeys::check(strtolower(trim($key))); // a later or unknown key says so before a value is asked for
        }
        if (! is_string($key) || $key === '' || ! is_string($value) || trim($value) === '') {
            throw InvalidInput::usage('Use `fsa config set <key> <value>`; `fsa config show` lists the keys.');
        }
        $confirm = $this->canPrompt($context, ignoreYes: true) ? fn (string $question): bool => $this->confirm($question) : null;

        return $this->laravel->make(ConfigSetter::class)->set($this->request($key, $value), $context, $confirm);
    }

    private function request(string $key, string $value): ConfigRequest
    {
        $email = $this->option('acme-email');
        $ca = $this->option('acme-ca');
        $root = $this->option('acme-ca-root-file');
        $ca = is_string($ca) && $ca !== '' ? $ca : null;
        if ($this->option('acme-staging') === true) {
            if ($ca !== null) {
                throw new InvalidInput('--acme-staging and --acme-ca exclude each other.', 'invalid_flag', 'Pass one of them: --acme-staging selects Let\'s Encrypt staging.');
            }
            $ca = InstanceSettings::ACME_STAGING;
        }

        return new ConfigRequest(
            strtolower(trim($key)),
            $value,
            is_string($email) && $email !== '' ? $email : null,
            $ca,
            is_string($root) && $root !== '' ? AcmeCaRoot::read($root) : null,
        );
    }
}
