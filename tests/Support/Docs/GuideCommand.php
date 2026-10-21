<?php

declare(strict_types=1);

namespace Tests\Support\Docs;

/**
 * Turns a runnable block of the agent guide into the command J11 runs against the local release (plan U21). The guide
 * shows the commands a person runs on a real host; the doc-test changes only what the harness stands in for:
 *
 * - the one-liner `curl … releases/latest/download/install.sh | sh -s --` becomes the harness release's install.sh with
 *   `--version` (harness releases are pre-releases, which never land in `latest/download`), unless the block passes its
 *   own `--version` (the move installs the old host's version, `<old-version>`);
 * - `~/forms…` (the instance directories) become `fieldset-test-j11-forms…` in the journey's work directory, so every Docker
 *   resource carries `fieldset-test-`; any other `~/` path becomes the work directory;
 * - `smtp.example.com --smtp-port 587` becomes the stand-in Mailpit on the instance network, plain SMTP on 1025;
 * - `<name>` placeholders become values from earlier results.
 */
final readonly class GuideCommand
{
    public const string ONE_LINER = '~curl\s[^|]*/install\.sh\s*\|\s*sh -s --~';

    /**
     * @param  array<string, string>  $placeholders  `<backup>` => value
     */
    public function __construct(
        private string $installScript,
        private string $version,
        private string $work,
        private ?string $smtpHost = null,
        private array $placeholders = [],
    ) {}

    public function prepare(string $command): string
    {
        $command = (string) preg_replace('/\\\\\n\s*/', ' ', $command);
        $version = preg_match('/\s--version[\s=]/', $command) === 1 ? '' : ' --version '.escapeshellarg($this->version); // a block's own wins
        $command = (string) preg_replace_callback(self::ONE_LINER, fn (): string => 'sh '.escapeshellarg($this->installScript).$version, $command);
        $command = str_replace(['~/forms', '~/'], [$this->work.'/fieldset-test-j11-forms', $this->work.'/'], $command);
        if ($this->smtpHost !== null) {
            $command = str_replace('smtp.example.com --smtp-port 587', $this->smtpHost.' --smtp-port 1025 --smtp-encryption none', $command);
        }

        return strtr($command, $this->placeholders);
    }

    /** @return list<string> the `<name>` placeholders still in a prepared command */
    public static function unresolved(string $command): array
    {
        preg_match_all('/<[a-z][a-z-]*>/', $command, $matches);

        return array_values(array_unique($matches[0]));
    }
}
