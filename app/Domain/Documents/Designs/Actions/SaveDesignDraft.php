<?php

declare(strict_types=1);

namespace App\Domain\Documents\Designs\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Documents\Designs\DesignDiff;
use App\Domain\Documents\Designs\DesignSettings;
use App\Domain\Documents\Models\Design;
use App\Domain\Identity\Models\User;
use InvalidArgumentException;

/** Stores the mutable draft (name + normalized settings). Published versions are untouched. */
final readonly class SaveDesignDraft
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $settings
     * @param  bool  $audit  false when a publish saves pending edits: its design.published entry is the one entry (§8.11)
     */
    public function handle(Design $design, string $name, array $settings, User $actor, bool $audit = true): Design
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) {
            throw new InvalidArgumentException(__('documents.errors.name_required'));
        }

        $before = DesignSettings::fromArray($design->settings)->toArray();
        $after = DesignSettings::fromArray($settings)->toArray();
        $changed = array_map(static fn (array $change): string => $change['setting'], DesignDiff::compare($before, $after));
        if ($name !== $design->name) {
            $changed[] = 'name';
        }
        if ($changed === []) {
            return $design;
        }

        $design->forceFill(['name' => $name, 'settings' => $after, 'updated_by' => $actor->id])->save();
        if ($audit) {
            $this->audit->record('design.updated', $design, ['changed' => $changed], $actor);
        }

        return $design;
    }
}
