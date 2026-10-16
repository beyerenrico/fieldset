<?php

declare(strict_types=1);

namespace App\Domain\Audit;

/**
 * A model whose audited attribute values need masking beyond the key-based Redactor (e.g. a token inside a
 * webhook URL). EnrichActivity applies it to the `attributes` and `old` values of the model's audit entries.
 */
interface MasksAuditAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function maskAuditAttributes(array $attributes): array;
}
