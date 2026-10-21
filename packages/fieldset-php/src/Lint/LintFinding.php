<?php

declare(strict_types=1);

namespace Fieldset\Lint;

use JsonSerializable;

/**
 * A lint result (spec §7). `path` is a JSON pointer into the definition.
 */
final readonly class LintFinding implements JsonSerializable
{
    public const ERROR = 'error';

    public const WARNING = 'warning';

    /**
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        public string $severity,
        public string $code,
        public string $path,
        public array $params = [],
    ) {}

    /**
     * @param  array<string, mixed>  $params
     */
    public static function error(string $code, string $path, array $params = []): self
    {
        return new self(self::ERROR, $code, $path, $params);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public static function warning(string $code, string $path, array $params = []): self
    {
        return new self(self::WARNING, $code, $path, $params);
    }

    public function isError(): bool
    {
        return $this->severity === self::ERROR;
    }

    /**
     * @return array{severity: string, code: string, path: string, params?: array<string, mixed>}
     */
    public function toArray(): array
    {
        $finding = ['severity' => $this->severity, 'code' => $this->code, 'path' => $this->path];

        if ($this->params !== []) {
            $finding['params'] = $this->params;
        }

        return $finding;
    }

    /**
     * @return array{severity: string, code: string, path: string, params?: array<string, mixed>}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
