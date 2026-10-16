<?php

declare(strict_types=1);

namespace App\Domain\Documents\Designs\Actions;

use App\Domain\Documents\Models\Design;
use App\Domain\Documents\Models\DesignVersion;
use App\Domain\Identity\Models\User;
use InvalidArgumentException;

/** Rollback to version K publishes a NEW version whose settings are a copy of K (§7.6, §8.10). */
final readonly class RollbackDesign
{
    public function __construct(private PublishDesign $publish) {}

    public function handle(Design $design, DesignVersion $target, User $actor, ?string $changelog = null): DesignVersion
    {
        if ($target->design_id !== $design->id) {
            throw new InvalidArgumentException('The version belongs to another design.');
        }

        $note = $changelog !== null && trim($changelog) !== ''
            ? $changelog
            : __('documents.versions.rollback_note', ['version' => $target->version]);

        return $this->publish->handle($design, $note, $actor, $target->settings, 'design.rolled_back');
    }
}
