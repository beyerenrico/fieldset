<?php

declare(strict_types=1);

namespace App\Domain\Documents\Designs\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Documents\Designs\DesignSettings;
use App\Domain\Documents\Models\Design;
use App\Domain\Documents\Models\DesignVersion;
use App\Domain\Identity\Models\User;
use Fieldset\Json\Json;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Publish = snapshot the (normalized) settings as immutable version N+1 with checksum and changelog,
 * like forms (§7.6, §8.10). The draft is set to the published settings.
 */
final readonly class PublishDesign
{
    public function __construct(private AuditLogger $audit) {}

    /** @param  array<string, mixed>|null  $settings  defaults to the draft */
    public function handle(Design $design, string $changelog, ?User $actor, ?array $settings = null, string $event = 'design.published'): DesignVersion
    {
        $changelog = trim($changelog);
        if ($changelog === '') {
            throw new InvalidArgumentException(__('documents.errors.changelog_required'));
        }
        $normalized = DesignSettings::fromArray($settings ?? $design->settings)->toArray();

        return DB::transaction(function () use ($design, $changelog, $actor, $normalized, $event): DesignVersion {
            // Serialize concurrent publishes of the same design.
            Design::query()->whereKey($design->id)->lockForUpdate()->first();

            $version = DesignVersion::query()->create([
                'design_id' => $design->id,
                'version' => $design->latestVersionNumber() + 1,
                'settings' => $normalized,
                'checksum' => Json::checksum($normalized),
                'changelog' => $changelog,
                'published_by' => $actor?->id,
                'published_at' => now(),
            ]);

            $design->forceFill(['current_version_id' => $version->id, 'settings' => $normalized, 'updated_by' => $actor->id ?? $design->updated_by])->save();

            $this->audit->record($event, $version, [
                'design_id' => $design->id, 'version' => $version->version, 'checksum' => $version->checksum,
            ], $actor);

            return $version;
        });
    }
}
