<?php

declare(strict_types=1);

namespace App\Domain\Forms\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Forms\Enums\FormStatus;
use App\Domain\Forms\Models\Form;
use App\Domain\Identity\Models\User;

/** Archives a form (hidden from the Field App, history kept) or restores it. */
final readonly class SetFormStatus
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Form $form, FormStatus $status, User $actor): Form
    {
        if ($status === FormStatus::Active && $form->current_version_id === null) {
            $status = FormStatus::Draft;
        }

        $before = $form->status;
        if ($before === $status) {
            return $form;
        }

        $form->forceFill(['status' => $status, 'updated_by' => $actor->id])->save();
        $this->audit->record('form.status_changed', $form, ['before' => $before->value, 'after' => $status->value], $actor);

        return $form;
    }
}
