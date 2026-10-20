<?php

declare(strict_types=1);

namespace App\Domain\Instance\Output;

use App\Domain\Instance\InstanceException;

/**
 * A destructive command without a TTY (or with --json) and without --yes (exit 6). Nothing was changed. `data` holds the
 * question and the facts it names (Confirmation, M12 gate O6).
 */
final class ConfirmationRequired extends InstanceException
{
    /** @param  array<string, mixed>  $data */
    public function __construct(string $question, array $data = [])
    {
        parent::__construct(
            "Confirmation required: {$question}",
            ExitCode::ConfirmationRequired,
            'confirmation_required',
            'Re-run with --yes to confirm.',
            false,
            ['question' => $question, ...$data],
        );
    }

    public static function of(Confirmation $confirmation): self
    {
        return new self($confirmation->question, $confirmation->data);
    }
}
