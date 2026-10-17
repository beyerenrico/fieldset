<?php

declare(strict_types=1);

namespace App\Domain\Instance\State;

use App\Domain\Instance\Compose\ResourceBudget;

/**
 * Typed reads of the state's settings with their defaults, for the renderers (plan U3). The values were checked
 * against InputGrammar when they were written and again when the state was read.
 */
final readonly class InstanceSettings
{
    public const int DEFAULT_PORT = 8080;

    public const int DEFAULT_MAIL_UI_PORT = 8025;

    public const int DEFAULT_SMTP_PORT = 587;

    public const string DEFAULT_DATABASE = 'form_schema';

    public const string DEFAULT_SENDER_NAME = 'form-schema-app';

    private function __construct(private InstanceState $state) {}

    public static function of(InstanceState $state): self
    {
        return new self($state);
    }

    /** The app's loopback port in local mode (R15: 8080 or the next free one, chosen by install). */
    public function port(): int
    {
        return $this->int('port', self::DEFAULT_PORT);
    }

    /** The catcher UI's loopback port in local and proxy modes (KTD13). */
    public function mailUiPort(): int
    {
        return $this->int('mail_ui_port', self::DEFAULT_MAIL_UI_PORT);
    }

    /** Capture mode (E8) until SMTP is configured. */
    public function capturesMail(): bool
    {
        return $this->state->setting('mail', 'capture') !== 'smtp';
    }

    public function size(): string
    {
        return $this->string('size') ?? ResourceBudget::DEFAULT_SIZE;
    }

    public function dbDatabase(): string
    {
        return $this->string('db_database') ?? self::DEFAULT_DATABASE;
    }

    public function dbUsername(): string
    {
        return $this->string('db_username') ?? self::DEFAULT_DATABASE;
    }

    public function smtpHost(): ?string
    {
        return $this->string('smtp_host');
    }

    public function smtpPort(): int
    {
        return $this->int('smtp_port', self::DEFAULT_SMTP_PORT);
    }

    /** null (STARTTLS when offered) or "smtps" (implicit TLS, port 465). */
    public function smtpScheme(): ?string
    {
        return $this->string('smtp_scheme');
    }

    public function mailFromAddress(): string
    {
        $domain = $this->string('domain');

        return $this->string('mail_from_address') ?? 'forms@'.($domain !== null && ! InputGrammar::isIp($domain) ? $domain : 'localhost');
    }

    public function mailFromName(): string
    {
        return $this->string('mail_from_name') ?? self::DEFAULT_SENDER_NAME;
    }

    /** @return list<string> further host names the app answers on (KTD11; plain names or IPs, no wildcards) */
    public function extraHosts(): array
    {
        return InputGrammar::hostList($this->string('extra_hosts') ?? '') ?? [];
    }

    /** The loopback URL in local mode; other modes publish no app port of their own. */
    public function localUrl(): ?string
    {
        return $this->state->mode() === 'local' ? 'http://localhost:'.$this->port() : null;
    }

    /** The URL in the state (domain, proxy, `config set url`), else the local URL. */
    public function instanceUrl(): string
    {
        return $this->state->url() ?? $this->localUrl()
            ?? throw new InvalidState("The instance state has no URL for mode [{$this->state->mode()}].");
    }

    private function int(string $key, int $default): int
    {
        $value = $this->state->setting($key);

        return is_int($value) ? $value : $default;
    }

    private function string(string $key): ?string
    {
        $value = $this->state->setting($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
