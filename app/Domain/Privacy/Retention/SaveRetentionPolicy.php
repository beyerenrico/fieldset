<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Retention;

use App\Domain\Audit\AuditLogger;
use App\Domain\Forms\Models\Form;
use App\Domain\Identity\Models\User;
use App\Domain\Privacy\Enums\RetentionAction;
use App\Domain\Privacy\Holds\LegalHolds;
use App\Domain\Privacy\Models\RetentionPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Sets a form's retention (ADR 0039): `months = null` keeps submissions indefinitely, otherwise 1–1200 months
 * and an action (delete or anonymize). A form under legal hold may not get less than the legal-hold minimum.
 * One audit entry `privacy.retention_changed` per effective change.
 */
final readonly class SaveRetentionPolicy
{
    public const int MAX_MONTHS = 1200;

    public function __construct(private AuditLogger $audit) {}

    public function handle(Form $form, ?int $months, RetentionAction $action, User $actor): RetentionPolicy
    {
        $existing = RetentionPolicy::query()->where('form_id', $form->id)->first();
        Gate::forUser($actor)->authorize($existing instanceof RetentionPolicy ? 'update' : 'create', $existing ?? RetentionPolicy::class);

        return DB::transaction(function () use ($form, $months, $action, $actor): RetentionPolicy {
            // Serialized with PlaceLegalHold (same form row lock): the hold check below cannot go stale.
            DB::table('forms')->where('id', $form->id)->lockForUpdate()->first(['id']);
            self::ensureValid($form, $months);
            $policy = RetentionPolicy::query()->firstOrNew(['form_id' => $form->id]);
            $before = $policy->exists ? ['months' => $policy->months, 'action' => $policy->action->value] : null;
            $after = ['months' => $months, 'action' => $action->value];
            if ($before === $after) {
                return $policy;
            }
            $policy->forceFill(['form_id' => $form->id, 'months' => $months, 'action' => $action, 'updated_by' => $actor->id])->save();
            $this->audit->record('privacy.retention_changed', $form, ['before' => $before, 'after' => $after], $actor);

            return $policy;
        });
    }

    /** Also called by the Retention page before it asks to confirm a shorter period (errors at the field). */
    public static function ensureValid(Form $form, ?int $months): void
    {
        if ($months !== null && ($months < 1 || $months > self::MAX_MONTHS)) {
            throw ValidationException::withMessages(['months' => __('privacy.retention.months_invalid', ['max' => self::MAX_MONTHS])]);
        }
        if ($months !== null && $months < LegalHolds::minMonths() && LegalHolds::formHeld($form->id)) {
            throw ValidationException::withMessages(['months' => __('privacy.retention.below_hold_minimum', ['months' => LegalHolds::minMonths()])]);
        }
    }
}
