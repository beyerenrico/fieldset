<?php

declare(strict_types=1);

namespace App\Domain\Instance\Config;

use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;

/**
 * A planned settings change (ADR 0077): the state before and after, the values to report (`from`, `to`; never a
 * secret: every settable key is a plain value), and files the change brings along (a custom ACME CA root). The
 * after-state is the whole target: the renderer derives env files, compose file, Caddyfile and runtime from it, with
 * the SMTP credentials of `config set mail` (ADR 0080) when they change: those exist only here and in the env files.
 */
final readonly class ConfigChange
{
    /** @param  list<array{code: string, message: string, hint: string|null}>  $warnings */
    public function __construct(
        public string $key,
        public InstanceState $before,
        public InstanceState $after,
        public ?string $from,
        public ?string $to,
        public ?string $caRoot = null,
        public array $warnings = [],
        public ?SmtpCredentials $credentials = null,
    ) {}

    /**
     * No difference in mode, URL or any setting (a setting set to null equals an absent one, a default port its default),
     * no new CA root and no new SMTP credentials.
     */
    public function unchanged(): bool
    {
        return $this->essence($this->before) === $this->essence($this->after) && $this->caRoot === null && ! $this->credentials instanceof SmtpCredentials;
    }

    public function changesUrl(): bool
    {
        return $this->oldUrl() !== $this->newUrl();
    }

    public function oldUrl(): string
    {
        return InstanceSettings::of($this->before)->instanceUrl();
    }

    public function newUrl(): string
    {
        return InstanceSettings::of($this->after)->instanceUrl();
    }

    /** The same change with a refined target (a port chosen by the preflight, a proxy resolved on the network). */
    public function withAfter(InstanceState $after, ?string $to = null): self
    {
        return new self($this->key, $this->before, $after, $this->from, $to ?? $this->to, $this->caRoot, $this->warnings, $this->credentials);
    }

    public function withWarning(string $code, string $message, ?string $hint = null): self
    {
        return new self($this->key, $this->before, $this->after, $this->from, $this->to, $this->caRoot, [...$this->warnings, ['code' => $code, 'message' => $message, 'hint' => $hint]], $this->credentials);
    }

    /** The same change rendered with these SMTP credentials (a resumed `config set mail` gets them from its request). */
    public function withCredentials(?SmtpCredentials $credentials): self
    {
        return new self($this->key, $this->before, $this->after, $this->from, $this->to, $this->caRoot, $this->warnings, $credentials);
    }

    /**
     * The SMTP credentials the target renders with (ADR 0080 amendment): the new ones; none when SMTP moves to another
     * server and no account was named, so an account never follows a host change; else null, those in effect. Derived
     * from the two states, so a resumed run (whose request carries no account) renders the same.
     */
    public function targetCredentials(): ?SmtpCredentials
    {
        if ($this->credentials instanceof SmtpCredentials) {
            return $this->credentials;
        }
        $before = InstanceSettings::of($this->before);
        $after = InstanceSettings::of($this->after);

        return ! $before->capturesMail() && ! $after->capturesMail() && $before->smtpHost() !== $after->smtpHost() ? new SmtpCredentials(null, null) : null;
    }

    /** @return array{key: string, from: string|null, to: string|null, url: array{from: string, to: string}, mode: array{from: string, to: string}} */
    public function summary(): array
    {
        return [
            'key' => $this->key, 'from' => $this->from, 'to' => $this->to,
            'url' => ['from' => $this->oldUrl(), 'to' => $this->newUrl()],
            'mode' => ['from' => $this->before->mode(), 'to' => $this->after->mode()],
        ];
    }

    /** @return array{mode: string, url: string|null, settings: array<string, mixed>} */
    private function essence(InstanceState $state): array
    {
        $settings = $state->toArray()['settings'] ?? [];
        $settings = array_filter(is_array($settings) ? $settings : [], static fn (mixed $value): bool => $value !== null);
        // Absent ports mean their defaults (InstanceSettings), so setting a port to its default changes nothing.
        $settings += ['port' => InstanceSettings::DEFAULT_PORT, 'mail_ui_port' => InstanceSettings::DEFAULT_MAIL_UI_PORT];
        ksort($settings);

        return ['mode' => $state->mode(), 'url' => $state->url(), 'settings' => $settings];
    }
}
