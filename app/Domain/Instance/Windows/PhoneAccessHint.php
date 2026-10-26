<?php

declare(strict_types=1);

namespace App\Domain\Instance\Windows;

/**
 * What PhoneAccessRows found: the matching row of the hint table (null: Windows lets phones through), a sentence, and
 * the one command set to print (KTD10), built by WindowsCommands from validated values only (KTD9).
 */
final readonly class PhoneAccessHint
{
    /**
     * @param  list<string>  $commands  copy-ready lines for PowerShell as administrator
     * @param  string|null  $advice  prose before the command lines (or instead of them)
     * @param  list<string>  $unknown  the facts Windows did not report (row FactsUnknown)
     */
    public function __construct(
        public ?PhoneAccessRow $row,
        public string $summary,
        public array $commands = [],
        public ?string $advice = null,
        public array $unknown = [],
    ) {}

    /** The fix as printed: the advice, then the header and the command lines. */
    public function hint(): ?string
    {
        if (! $this->row instanceof PhoneAccessRow) {
            return null;
        }
        $commands = $this->commands === [] ? null : WindowsCommands::hint($this->commands);

        return implode("\n", array_filter([$this->advice, $commands], is_string(...)));
    }
}
