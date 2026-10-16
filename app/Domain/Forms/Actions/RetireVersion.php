<?php

declare(strict_types=1);

namespace App\Domain\Forms\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Forms\Models\FormVersion;
use App\Domain\Identity\Models\User;

/** A retired version accepts no new submissions (§7.6). Retiring is the only allowed version update. */
final readonly class RetireVersion
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(FormVersion $version, User $actor): FormVersion
    {
        if ($version->isRetired()) {
            return $version;
        }

        // Query-level update: only retired_at changes (the trigger allows exactly this), even if the
        // in-memory model holds re-encoded JSON attributes that would look dirty.
        FormVersion::query()->whereKey($version->id)->whereNull('retired_at')->update(['retired_at' => now()]);
        $version->refresh();

        $this->audit->record('form.version_retired', $version, ['form_id' => $version->form_id, 'version' => $version->version], $actor);

        return $version;
    }
}
