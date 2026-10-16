<?php

declare(strict_types=1);

namespace App\Http\Api\V1\Controllers;

use App\Domain\Forms\Models\Form;
use App\Domain\Forms\Models\FormAccess;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Submissions\Support\FormAccessResolver;
use App\Domain\Workflows\Enums\TaskStatus;
use App\Domain\Workflows\Models\Task;
use App\Domain\Workflows\Tasks\TaskAccess;
use App\Domain\Workflows\Tasks\TaskPresenter;
use App\Http\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * `GET /sync/manifest?since=` (§9.2): forms changed since the cursor, plus the complete list of
 * fillable form ids so the client can purge revoked forms even when the revocation happened through a
 * group change that touches no form, and every open review task the user may complete (M4).
 *
 * "Changed" is inclusive (`>=`, the cursor has second precision) and covers forms whose grants changed
 * (SyncFormAccess touches the form) or that the user reached through a group membership added since
 * the cursor. Duplicates across manifests are expected; clients upsert by form id.
 */
final class SyncController
{
    /** Open review tasks in the manifest (newest first, complete up to this many). */
    public const int TASK_LIMIT = 200;

    public function manifest(Request $request, FormAccessResolver $access, TenantContext $tenant, TaskAccess $tasks): JsonResponse
    {
        $request->validate(['since' => ['nullable', 'date']]);
        /** @var User $user */
        $user = $request->user();
        $since = $request->filled('since') ? Carbon::parse((string) $request->query('since'))->setTimezone((string) config('app.timezone')) : null;
        $cursor = Carbon::now()->toIso8601String();

        $fillable = $access->fillable($user)->with('currentVersion')->get();
        $viaMembership = $since instanceof Carbon ? $this->formsReachedThroughNewMemberships($user, $tenant->require(), $since) : [];
        $changed = $since instanceof Carbon ? $fillable->filter(fn (Form $f): bool => $f->updated_at === null
            || $f->updated_at->greaterThanOrEqualTo($since) || in_array((int) $f->id, $viaMembership, true)) : $fillable;
        $fillableIds = $fillable->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all();
        $revoked = $since instanceof Carbon ? Form::query()->where('updated_at', '>=', $since)->whereNotIn('id', $fillableIds)
            ->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all() : [];

        return ApiResponse::ok([
            'cursor' => $cursor,
            'forms' => $changed->map(fn (Form $form): array => FormController::summary($form))->values()->all(),
            'fillable_form_ids' => $fillableIds,
            'revoked_form_ids' => $revoked,
            'tasks' => TaskPresenter::presentMany($tasks->actionable(Task::query(), $user)->where('status', TaskStatus::Open->value)
                ->orderByDesc('created_at')->orderByDesc('id')->limit(self::TASK_LIMIT)->get()),
        ]);
    }

    /** @return list<int> ids of forms granted to groups the user joined (or re-joined) since the cursor */
    private function formsReachedThroughNewMemberships(User $user, int $organizationId, Carbon $since): array
    {
        $groupIds = DB::table('group_user')->where('organization_id', $organizationId)->where('user_id', $user->id)
            ->where('updated_at', '>=', $since)->pluck('group_id')->all();

        return $groupIds === [] ? [] : array_values(FormAccess::query()->whereIn('group_id', $groupIds)->pluck('form_id')
            ->map(fn (mixed $id): int => (int) $id)->unique()->all());
    }
}
