<?php

declare(strict_types=1);

namespace App\Domain\Documents\Designs\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Documents\Models\Design;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/** Exactly one default design per organization (partial unique index); used when a form names none. */
final readonly class SetDefaultDesign
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Design $design, User $actor): Design
    {
        if ($design->is_default) {
            return $design;
        }

        DB::transaction(function () use ($design, $actor): void {
            Design::query()->where('is_default', true)->lockForUpdate()->get()
                ->each(fn (Design $other) => $other->forceFill(['is_default' => false])->save());
            $design->forceFill(['is_default' => true, 'updated_by' => $actor->id])->save();
            $this->audit->record('design.default_set', $design, ['key' => $design->key], $actor);
        });

        return $design;
    }
}
