<?php

declare(strict_types=1);

namespace App\Domain\Instance\Output;

use Carbon\CarbonImmutable;
use SensitiveParameter;

/**
 * A one-time output that JSON marks as secret (R50): a sign-in link, a demo password, generated catcher
 * credentials. It is rendered only under the envelope's `secrets` or written to `--secrets-file`.
 */
final readonly class Secret
{
    public function __construct(
        public string $name,
        #[SensitiveParameter] public string $value,
        public ?CarbonImmutable $expiresAt = null,
    ) {}

    /**
     * @param  string|null  $writtenTo  the secrets file holding the value; the value is then left out
     * @return array{name: string, value?: string, written_to?: string, expires_at: string|null}
     */
    public function toArray(?string $writtenTo = null): array
    {
        $expires = $this->expiresAt?->utc()->toIso8601ZuluString();

        return $writtenTo === null
            ? ['name' => $this->name, 'value' => $this->value, 'expires_at' => $expires]
            : ['name' => $this->name, 'written_to' => $writtenTo, 'expires_at' => $expires];
    }

    /** @return array{name: string, value: string} */
    public function __debugInfo(): array
    {
        return ['name' => $this->name, 'value' => '[redacted]'];
    }
}
