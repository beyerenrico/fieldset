<?php

declare(strict_types=1);

namespace App\Domain\Forms\Import;

use App\Domain\Forms\Models\Form;
use App\Domain\Identity\Models\User;

/** Hides the PDF import report of a form for good (§8.13: visible until someone dismisses it). */
final class DismissImportReport
{
    public function handle(Form $form, User $actor): void
    {
        $form->forceFill(['import_report' => null, 'updated_by' => $actor->id])->save();
    }
}
