<?php

declare(strict_types=1);

namespace App\Domain\Forms\Builder;

/** Maps one definition property (by path) to one builder form field. */
final readonly class FieldSpec
{
    public const string STRING = 'string';

    public const string BOOL = 'bool';

    public const string INT = 'int';

    public const string NUMERIC = 'numeric';

    public const string OPTIONS = 'options';

    public const string LOGIC = 'logic';

    public const string CALC = 'calc';

    public const string DEFAULT = 'default';

    public const string LIST = 'list';

    /** @param  list<string>  $path */
    public function __construct(public string $ui, public array $path, public string $kind) {}
}
