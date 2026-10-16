<?php

declare(strict_types=1);

namespace App\Domain\Documents\Designs\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Documents\Models\DesignVersion;
use App\Domain\Identity\Models\User;

/**
 * A retired design version is no longer chosen for new documents; documents already pinned to it
 * keep re-rendering with it (§8.5 AC). Retiring is the only allowed version update.
 */
final readonly class RetireDesignVersion
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(DesignVersion $version, User $actor): DesignVersion
    {
        if ($version->isRetired()) {
            return $version;
        }

        DesignVersion::query()->whereKey($version->id)->whereNull('retired_at')->update(['retired_at' => now()]);
        $version->refresh();

        $this->audit->record('design.version_retired', $version, ['design_id' => $version->design_id, 'version' => $version->version], $actor);

        return $version;
    }
}
