<?php

declare(strict_types=1);

namespace App\Http\Api\V1\Controllers;

use App\Domain\Forms\Models\Form;
use App\Domain\Forms\Models\FormVersion;
use App\Domain\Identity\Models\User;
use App\Domain\Submissions\Support\FormAccessResolver;
use App\Http\Api\ApiException;
use App\Http\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use stdClass;

/** Forms the user may fill and their versions (§10). Access is enforced on every call (§9.3). */
final class FormController
{
    public function __construct(private readonly FormAccessResolver $access) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $forms = $this->access->fillable($user)->with('currentVersion')->orderBy('name')->get();

        return ApiResponse::ok($forms->map(fn (Form $form): array => self::summary($form))->values()->all());
    }

    public function version(Request $request, int $form, int $version): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $model = Form::query()->find($form);
        if ($model === null || ! $this->access->canFill($user, $model)) {
            throw new ApiException('not_found', __('api.not_found'), 404);
        }
        $formVersion = FormVersion::query()->where('form_id', $model->id)->whereKey($version)->first()
            ?? throw new ApiException('not_found', __('api.not_found'), 404);

        $settings = $formVersion->definition->settings ?? null;
        $document = $settings instanceof stdClass ? ($settings->document ?? null) : null;

        return ApiResponse::ok([
            'id' => $formVersion->id, 'form_id' => $model->id, 'version' => $formVersion->version,
            'checksum' => $formVersion->checksum, 'definition' => $formVersion->definition,
            'design' => $document instanceof stdClass && is_string($document->design ?? null) ? $document->design : 'default',
            'published_at' => $formVersion->published_at->toIso8601String(), 'retired' => $formVersion->isRetired(),
        ]);
    }

    /** @return array<string, mixed> */
    public static function summary(Form $form): array
    {
        $version = $form->currentVersion;

        return [
            'id' => $form->id, 'key' => $form->key, 'name' => $form->name, 'category' => $form->category,
            'version' => $version === null ? null : ['id' => $version->id, 'version' => $version->version, 'checksum' => $version->checksum],
        ];
    }
}
