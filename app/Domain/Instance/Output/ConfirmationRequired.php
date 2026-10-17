<?php

declare(strict_types=1);

namespace App\Domain\Instance\Output;

use App\Domain\Instance\InstanceException;

/** A destructive command without a TTY (or with --json) and without --yes (exit 6). Nothing was changed. */
final class ConfirmationRequired extends InstanceException
{
    public function __construct(string $question)
    {
        parent::__construct(
            "Confirmation required: {$question}",
            ExitCode::ConfirmationRequired,
            'confirmation_required',
            'Re-run with --yes to confirm.',
        );
    }
}
