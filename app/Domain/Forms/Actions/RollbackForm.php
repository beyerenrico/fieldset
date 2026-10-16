<?php

declare(strict_types=1);

namespace App\Domain\Forms\Actions;

use App\Domain\Forms\Models\Form;
use App\Domain\Forms\Models\FormVersion;
use App\Domain\Identity\Models\User;
use InvalidArgumentException;

/** Rollback to version K publishes a NEW version whose content is a copy of K (§7.6). */
final readonly class RollbackForm
{
    public function __construct(private PublishForm $publish) {}

    public function handle(Form $form, FormVersion $target, User $actor, ?string $changelog = null): FormVersion
    {
        if ($target->form_id !== $form->id) {
            throw new InvalidArgumentException('The version belongs to another form.');
        }

        $note = $changelog !== null && trim($changelog) !== ''
            ? $changelog
            : __('forms.versions.rollback_note', ['version' => $target->version]);

        return $this->publish->handle($form, $note, $actor, $target->definition, 'form.rolled_back');
    }
}
