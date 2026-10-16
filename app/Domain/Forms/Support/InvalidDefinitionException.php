<?php

declare(strict_types=1);

namespace App\Domain\Forms\Support;

use RuntimeException;

/** A definition failed the meta-schema check or still has lint errors. */
final class InvalidDefinitionException extends RuntimeException
{
    public function __construct(public readonly DefinitionReport $report, string $message = '')
    {
        parent::__construct($message !== '' ? $message : __('forms.errors.invalid_definition'));
    }
}
