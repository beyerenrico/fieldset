<?php

declare(strict_types=1);

namespace App\Http\Api\V1\Controllers;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Submissions\Actions\SaveSubmissionDraft;
use App\Domain\Submissions\Actions\SubmissionPresenter;
use App\Domain\Submissions\Actions\SubmitSubmission;
use App\Domain\Submissions\Models\Submission;
use App\Domain\Submissions\Support\ClientSubmittedAt;
use App\Domain\Submissions\Support\SubmissionVisibility;
use App\Http\Api\ApiException;
use App\Http\Api\ApiResponse;
use FormSchema\Json\Json;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use stdClass;

/** Drafts, submit, list and detail (§9.2, §10). Thin: all rules live in the Submissions domain. */
final readonly class SubmissionController
{
    public function __construct(private SubmissionVisibility $visibility) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', 'string', 'max:24'], 'form_id' => ['nullable', 'integer']]);
        $user = $this->user($request);
        $page = $this->visibility->apply(Submission::query(), $user)
            ->when($request->filled('status'), fn ($q) => $q->where('status', (string) $request->query('status')))
            ->when($request->filled('form_id'), fn ($q) => $q->where('form_id', (int) $request->query('form_id')))
            ->with(['attachments', 'formVersion'])->orderByDesc('updated_at')->paginate(50);

        return ApiResponse::ok(
            collect($page->items())->map(fn (Submission $s): array => SubmissionPresenter::present($s, $user))->all(),
            ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        );
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        return ApiResponse::ok(['submission' => SubmissionPresenter::present($this->visible($request, $uuid), $this->user($request))]);
    }

    public function upsert(Request $request, string $uuid, SaveSubmissionDraft $save): JsonResponse
    {
        $this->assertUuid($uuid);
        $body = $this->jsonBody($request);
        $request->validate([
            'form_version_id' => ['required', 'integer'],
            'revision' => ['nullable', 'integer', 'min:0'],
        ]);
        if (! ($body->data ?? null) instanceof stdClass) {
            throw new ApiException('validation_failed', __('api.validation_failed'), 422, [['pointer' => '/data', 'code' => 'type_mismatch']]);
        }
        $revision = $request->filled('revision') && (int) $request->input('revision') > 0 ? (int) $request->input('revision') : null;

        [$submission, $created] = $save->handle(
            $this->user($request), strtolower($uuid), (int) $request->input('form_version_id'), $body->data, $revision,
            ($body->device ?? null) instanceof stdClass ? $body->device : null,
            ($body->geo ?? null) instanceof stdClass ? $body->geo : null,
        );

        return ApiResponse::ok(['submission' => SubmissionPresenter::present($submission->refresh(), $this->user($request))], null, $created ? 201 : 200);
    }

    public function submit(Request $request, string $uuid, SubmitSubmission $submit, TenantContext $tenant): JsonResponse
    {
        $request->validate(['revision' => ['required', 'integer', 'min:1'], 'client_submitted_at' => ClientSubmittedAt::rules()]);
        $submission = $this->visible($request, $uuid);
        $organization = $tenant->organization() ?? throw new ApiException('organization_required', __('api.organization_required'), 400);

        $submitted = $submit->handle(
            $this->user($request), $organization, $submission, (int) $request->input('revision'),
            ClientSubmittedAt::parse($request->input('client_submitted_at')),
        );

        return ApiResponse::ok(['submission' => SubmissionPresenter::present($submitted->refresh(), $this->user($request))]);
    }

    private function visible(Request $request, string $uuid): Submission
    {
        $this->assertUuid($uuid);
        $submission = Submission::query()->whereKey(strtolower($uuid))->first();
        if ($submission === null || ! $this->visibility->canView($this->user($request), $submission)) {
            throw new ApiException('not_found', __('api.not_found'), 404);
        }

        return $submission;
    }

    /** The raw body decoded with objects preserved (`{}` stays an object; spec §6). */
    private function jsonBody(Request $request): stdClass
    {
        $body = Json::decode($request->getContent() !== '' ? $request->getContent() : '{}');

        return $body instanceof stdClass ? $body : throw new ApiException('validation_failed', __('api.validation_failed'), 422);
    }

    private function assertUuid(string $uuid): void
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid) !== 1) {
            throw new ApiException('not_found', __('api.not_found'), 404);
        }
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
