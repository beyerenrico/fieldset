<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Console;

use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Privacy\Models\RetentionPolicy;
use App\Domain\Privacy\Retention\ApplyOrganizationRetention;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** Queues the retention job of every organization with a retention policy (ADR 0039); scheduled daily. */
#[Description('Delete or anonymize submissions whose retention period is over')]
#[Signature('privacy:apply-retention {--organization= : Only this organization}')]
final class ApplyRetentionCommand extends Command
{
    public function handle(TenantContext $tenant): int
    {
        $organizations = $tenant->withoutTenant(fn () => RetentionPolicy::query()->withoutTenant()->whereNotNull('months')
            ->when($this->option('organization') !== null, fn ($q) => $q->where('organization_id', (int) $this->option('organization')))
            ->distinct()->orderBy('organization_id')->pluck('organization_id'));

        foreach ($organizations as $organizationId) {
            $tenant->run((int) $organizationId, function () use ($organizationId): void {
                ApplyOrganizationRetention::dispatch((int) $organizationId);
            });
        }
        $this->components->info(sprintf('Queued retention for %d organization(s).', $organizations->count()));

        return self::SUCCESS;
    }
}
