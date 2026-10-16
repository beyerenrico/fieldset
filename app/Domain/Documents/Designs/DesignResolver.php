<?php

declare(strict_types=1);

namespace App\Domain\Documents\Designs;

use App\Domain\Documents\Designs\Actions\CreateDesign;
use App\Domain\Documents\Models\Design;
use App\Domain\Documents\Models\DesignVersion;
use App\Domain\Documents\Models\Rendition;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Submissions\Models\Submission;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Chooses the design version of a document (ADR 0028):
 *  - a submission that already has a rendition keeps that rendition's design version (pinned), so
 *    re-rendering after a design change reproduces the original document (§8.5 AC);
 *  - otherwise the design named by `settings.document.design` (its current, non-retired version),
 *    falling back to the organization's default design, created on first use. The spec's default
 *    value `default` (or no value) means "the organization's default design" (the `is_default`
 *    row), so "Make default" applies to every form that names no other design.
 */
final readonly class DesignResolver
{
    public function __construct(private CreateDesign $create, private TenantContext $tenant) {}

    public function forSubmission(Submission $submission): DesignVersion
    {
        $pinned = Rendition::query()->where('submission_uuid', $submission->uuid)->orderBy('id')->value('design_version_id');

        return is_numeric($pinned)
            ? DesignVersion::query()->whereKey((int) $pinned)->firstOrFail()
            : $this->forDefinition($submission->formVersion->definition);
    }

    public function forDefinition(stdClass $definition): DesignVersion
    {
        $design = $this->designFor($definition);
        $usable = self::usable($design);
        if ($usable instanceof DesignVersion) {
            return $usable;
        }
        $default = $this->defaultDesign();

        // Every design has version 1 from creation; a design whose versions are all retired still renders.
        return self::usable($default) ?? $default->versions()->reorder('version', 'desc')->firstOrFail();
    }

    /** The design a definition selects (by key), else the default design. */
    public function designFor(stdClass $definition): Design
    {
        $key = self::designKey($definition);
        $named = $key === CreateDesign::DEFAULT_KEY ? null : Design::query()->where('key', $key)->first();

        return $named ?? $this->defaultDesign();
    }

    public static function designKey(stdClass $definition): string
    {
        $document = ($definition->settings ?? null) instanceof stdClass ? ($definition->settings->document ?? null) : null;
        $key = $document instanceof stdClass ? ($document->design ?? null) : null;

        return is_string($key) && $key !== '' ? $key : CreateDesign::DEFAULT_KEY;
    }

    public function defaultDesign(): Design
    {
        return Design::query()->where('is_default', true)->first() ?? $this->createDefault();
    }

    /** Current version unless retired, else the newest non-retired version. */
    public static function usable(Design $design): ?DesignVersion
    {
        $current = $design->currentVersion;
        if ($current !== null && ! $current->isRetired()) {
            return $current;
        }

        return $design->versions()->whereNull('retired_at')->reorder('version', 'desc')->first();
    }

    /** Lazily creates the default design; an advisory lock serializes concurrent first renders. */
    private function createDefault(): Design
    {
        return DB::transaction(function (): Design {
            DB::select('select pg_advisory_xact_lock(?)', [crc32('designs.default.'.$this->tenant->require())]);

            return Design::query()->where('is_default', true)->first()
                ?? $this->create->handle((string) __('documents.designs.default_name'), null, [], CreateDesign::DEFAULT_KEY);
        });
    }
}
