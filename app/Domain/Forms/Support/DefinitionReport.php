<?php

declare(strict_types=1);

namespace App\Domain\Forms\Support;

use FormSchema\Lint\LintFinding;

/** Result of checking a definition: meta-schema errors and lint findings (spec §7.5). */
final readonly class DefinitionReport
{
    /**
     * @param  list<array{path: string, message: string}>  $schemaErrors
     * @param  list<LintFinding>  $findings
     */
    public function __construct(public array $schemaErrors, public array $findings) {}

    public function isStructurallyValid(): bool
    {
        return $this->schemaErrors === [];
    }

    public function hasErrors(): bool
    {
        if ($this->schemaErrors !== []) {
            return true;
        }

        return array_any($this->findings, fn (LintFinding $finding): bool => $finding->severity === 'error');
    }

    /** @return list<LintFinding> */
    public function errors(): array
    {
        return array_values(array_filter($this->findings, static fn (LintFinding $f): bool => $f->severity === 'error'));
    }

    /** @return list<LintFinding> */
    public function warnings(): array
    {
        return array_values(array_filter($this->findings, static fn (LintFinding $f): bool => $f->severity === 'warning'));
    }

    /** @return array{schema_errors: list<array{path: string, message: string}>, findings: list<array{severity: string, code: string, path: string}>} */
    public function toArray(): array
    {
        return [
            'schema_errors' => $this->schemaErrors,
            'findings' => array_map(static fn (LintFinding $f): array => [
                'severity' => $f->severity, 'code' => $f->code, 'path' => $f->path,
            ], $this->findings),
        ];
    }
}
