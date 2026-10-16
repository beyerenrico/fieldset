<?php

declare(strict_types=1);

namespace App\Domain\Dashboards\Performance;

use App\Domain\Forms\Models\Form;
use App\Domain\Forms\Models\FormVersion;
use App\Domain\Identity\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Fixed inputs of one load-generator run: organization, form version, members and optional related rows. */
final readonly class LoadContext
{
    /** @param  list<int>  $memberIds */
    private function __construct(
        public int $organizationId,
        public int $formId,
        public int $formVersionId,
        public string $run,
        public array $memberIds,
        public ?int $groupId,
        public ?int $designVersionId,
        public ?int $workflowId,
        public CarbonImmutable $now,
    ) {}

    public static function for(Organization $organization, Form $form, FormVersion $version, string $run): self
    {
        $orgId = $organization->id;
        $members = array_values(DB::table('organization_user')->where('organization_id', $orgId)->orderBy('user_id')->pluck('user_id')
            ->map(fn (mixed $id): int => (int) $id)->all());
        if ($members === []) {
            throw new InvalidArgumentException("Organization {$orgId} has no members.");
        }
        $id = fn (string $table): ?int => ($value = DB::table($table)->where('organization_id', $orgId)->orderBy('id')->value('id')) === null ? null : (int) $value;

        return new self($orgId, $form->id, $version->id, $run, $members, $id('groups'), $id('design_versions'), $id('workflows'), CarbonImmutable::now('UTC'));
    }

    public function member(): int
    {
        return $this->memberIds[array_rand($this->memberIds)];
    }
}
