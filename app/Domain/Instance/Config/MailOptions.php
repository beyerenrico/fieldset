<?php

declare(strict_types=1);

namespace App\Domain\Instance\Config;

/**
 * The flags of `fsa config set mail smtp` (R25, ADR 0080) as typed; MailApplier validates them. None is a secret: the
 * user name and password travel as SmtpCredentials. The journal records these options (not the test recipient, which
 * changes nothing), so a resumed run can tell the same change from another one.
 */
final readonly class MailOptions
{
    /** What `--smtp-username` shows in a command line to repeat: the name itself is a credential. */
    public const string USERNAME_PLACEHOLDER = '<the same user name>';

    public function __construct(
        public ?string $smtpHost = null,
        public ?string $smtpPort = null,
        public ?string $encryption = null,
        public ?string $fromAddress = null,
        public ?string $fromName = null,
        public ?string $testTo = null,
        public bool $removeCatcher = false,
        public bool $credentials = false,
    ) {}

    /** No SMTP flag at all (a test recipient alone changes nothing). */
    public function isEmpty(): bool
    {
        return $this->smtpHost === null && $this->smtpPort === null && $this->encryption === null && $this->fromAddress === null
            && $this->fromName === null && ! $this->removeCatcher && ! $this->credentials;
    }

    /** @return array<string, string|bool|null> */
    public function toContext(): array
    {
        return [
            'smtp_host' => $this->smtpHost, 'smtp_port' => $this->smtpPort, 'smtp_encryption' => $this->encryption,
            'from_address' => $this->fromAddress, 'from_name' => $this->fromName, 'remove_catcher' => $this->removeCatcher,
            'credentials' => $this->credentials,
        ];
    }

    /** @param  array<string, mixed>  $context */
    public static function fromContext(array $context): self
    {
        $string = static fn (string $key): ?string => is_string($context[$key] ?? null) && $context[$key] !== '' ? $context[$key] : null;

        return new self($string('smtp_host'), $string('smtp_port'), $string('smtp_encryption'), $string('from_address'), $string('from_name'), null,
            ($context['remove_catcher'] ?? false) === true, ($context['credentials'] ?? false) === true);
    }

    /**
     * The flags of a command line to repeat (the credentials as placeholders: a user name and `--password-stdin`).
     *
     * @return array<string, string|true>
     */
    public function flags(): array
    {
        return array_filter([
            '--smtp-host' => $this->smtpHost, '--smtp-port' => $this->smtpPort, '--smtp-encryption' => $this->encryption,
            '--smtp-username' => $this->credentials ? self::USERNAME_PLACEHOLDER : null, '--password-stdin' => $this->credentials ? true : null,
            '--from-address' => $this->fromAddress, '--from-name' => $this->fromName, '--remove-catcher' => $this->removeCatcher ? true : null,
        ], static fn (string|bool|null $value): bool => $value !== null);
    }
}
