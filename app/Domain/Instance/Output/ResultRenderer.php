<?php

declare(strict_types=1);

namespace App\Domain\Instance\Output;

use App\Domain\Instance\State\SafeFiles;
use App\Domain\Instance\State\UnsafePath;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Writes a CommandResult: the JSON envelope (one document on stdout) or readable text, and with a secrets file
 * the one-time secrets to that file (mode 0600, temp-file-plus-rename) instead of stdout (KTD3).
 */
final readonly class ResultRenderer
{
    public function __construct(private SecretRedactor $redactor) {}

    /**
     * @return int the exit code (2 when the secrets file could not be written; its secrets are then dropped, and a
     *             dropped sign-in link is named with the command that issues a new one)
     */
    public function render(CommandResult $result, OutputInterface $output, bool $json, ?string $secretsFile = null): int
    {
        if ($secretsFile !== null && $result->secrets !== []) {
            $result = $this->writeSecrets($result, $secretsFile);
        }
        $written = $secretsFile !== null && $result->secrets !== [] ? $secretsFile : null;
        if ($json) {
            $output->writeln($result->toJson($this->redactor, $written), OutputInterface::OUTPUT_RAW);
        } else {
            $this->human($result, $output, $written);
        }

        return $result->exitCode->value;
    }

    private function writeSecrets(CommandResult $result, string $path): CommandResult
    {
        $payload = [
            'schema_version' => CommandResult::SCHEMA_VERSION,
            'command' => $result->command,
            'secrets' => array_map(static fn (Secret $s): array => $s->toArray(), $result->secrets),
        ];

        try {
            SafeFiles::writeAtomically($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)."\n", 0600);

            return $result;
        } catch (UnsafePath $e) {
            $failed = CommandResult::failed($result->command, ExitCode::InvalidInput, 'secrets_file_unwritable', $e->getMessage(), $this->droppedHint($result), false, $result->data, $result->changed);

            return array_reduce($result->warnings, static fn (CommandResult $carry, Message $w): CommandResult => $carry->withWarning($w->code, $w->message, $w->hint), $failed);
        }
    }

    /**
     * Re-running install on a finished instance issues no link, so a dropped sign-in link is replaced through
     * `fieldset login-link` (with a writable --secrets-file this time).
     */
    private function droppedHint(CommandResult $result): string
    {
        if (! array_any($result->secrets, static fn (Secret $s): bool => $s->name === 'sign_in_link')) {
            return 'Pass a --secrets-file in an existing, writable directory.';
        }
        $email = $result->data['owner_email'] ?? $result->data['email'] ?? null;

        return 'The sign-in link was not saved. Issue a new one with `fieldset login-link '.(is_string($email) && $email !== '' ? $email : '<email>')
            .' --secrets-file <file>`, naming a file in an existing, writable directory.';
    }

    private function human(CommandResult $result, OutputInterface $output, ?string $secretsFile): void
    {
        $envelope = $result->toArray($this->redactor, $secretsFile);
        $output->writeln(sprintf('fieldset %s: %s%s', $result->command, $envelope['status'], $result->changed ? '' : ' (nothing changed)'));
        foreach ($envelope['data'] as $key => $value) {
            $output->writeln(sprintf('  %s: %s', $key, is_scalar($value) || $value === null ? var_export($value, true) : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)), OutputInterface::OUTPUT_RAW);
        }
        foreach ($envelope['secrets'] as $secret) {
            $where = $secret['value'] ?? 'written to '.($secret['written_to'] ?? '?');
            $output->writeln(sprintf('  %s: %s%s', $secret['name'], $where, isset($secret['expires_at']) ? " (expires {$secret['expires_at']})" : ''), OutputInterface::OUTPUT_RAW);
        }
        foreach ([['Warning', $envelope['warnings']], ['Error', $envelope['errors']]] as [$label, $messages]) {
            foreach ($messages as $message) {
                $output->writeln(sprintf('%s [%s]: %s', $label, $message['code'], $message['message']), OutputInterface::OUTPUT_RAW);
                if (is_string($message['hint'] ?? null)) {
                    $output->writeln('  Fix: '.$message['hint'], OutputInterface::OUTPUT_RAW);
                }
            }
        }
    }
}
