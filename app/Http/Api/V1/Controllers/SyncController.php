<?php

declare(strict_types=1);

namespace App\Http\Api\V1\Controllers;

use App\Domain\Forms\Models\Form;
use App\Domain\Identity\Models\User;
use App\Domain\Submissions\Support\FormAccessResolver;
use App\Http\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * `GET /sync/manifest?since=` (§9.2): forms changed since the cursor, plus the complete list of
 * fillable form ids so the client can purge revoked forms even when the revocation happened through a
 * group change that touches no form.
 */
final class SyncController
{
    public function manifest(Request $request, FormAccessResolver $access): JsonResponse
    {
        $request->validate(['since' => ['nullable', 'date']]);
        /** @var User $user */
        $user = $request->user();
        $since = $request->filled('since') ? Carbon::parse((string) $request->query('since')) : null;
        $cursor = Carbon::now()->toIso8601String();

        $fillable = $access->fillable($user)->with('currentVersion')->get();
        $changed = $since === null ? $fillable : $fillable->filter(fn (Form $f): bool => $f->updated_at === null || $f->updated_at->greaterThan($since));
        $fillableIds = $fillable->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all();
        $revoked = $since === null ? [] : Form::query()->where('updated_at', '>', $since)->whereNotIn('id', $fillableIds)
            ->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all();

        return ApiResponse::ok([
            'cursor' => $cursor,
            'forms' => $changed->map(fn (Form $form): array => FormController::summary($form))->values()->all(),
            'fillable_form_ids' => $fillableIds,
            'revoked_form_ids' => $revoked,
            'tasks' => [],
        ]);
    }
}
