<?php

declare(strict_types=1);

namespace App\Domain\Instance\Config;

use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;

/**
 * A planned settings change (ADR 0077): the state before and after, the values to report (`from`, `to`; never a
 * secret: every settable key is a plain value), and files the change brings along (a custom ACME CA root). The
 * after-state is the whole target: the renderer derives env files, compose file, Caddyfile and runtime from it.
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
    ) {}

    /** No difference in mode, URL or any setting (a setting set to null equals an absent one, a default port its default). */
    public function unchanged(): bool
    {
        return $this->essence($this->before) === $this->essence($this->after) && $this->caRoot === null;
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
        return new self($this->key, $this->before, $after, $this->from, $to ?? $this->to, $this->caRoot, $this->warnings);
    }

    public function withWarning(string $code, string $message, ?string $hint = null): self
    {
        return new self($this->key, $this->before, $this->after, $this->from, $this->to, $this->caRoot, [...$this->warnings, ['code' => $code, 'message' => $message, 'hint' => $hint]]);
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
