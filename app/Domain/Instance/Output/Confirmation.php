<?php

declare(strict_types=1);

namespace App\Domain\Instance\Output;

/**
 * The question a destructive command asks, with the facts it names as data (M12 gate O6): an agent that gets exit 6
 * `confirmation_required` reads them from `data` (a rollback's versions, safety backup and loss counts) instead of
 * parsing the question. Never a secret.
 */
final readonly class Confirmation
{
    /** @param  array<string, mixed>  $data */
    public function __construct(public string $question, public array $data = []) {}
}
