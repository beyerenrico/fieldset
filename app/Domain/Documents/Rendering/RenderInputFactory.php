<?php

declare(strict_types=1);

namespace App\Domain\Documents\Rendering;

use App\Domain\Documents\Branding\Branding;
use App\Domain\Documents\Designs\DesignSettings;
use App\Domain\Documents\Models\DesignVersion;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Submissions\Models\Attachment;
use App\Domain\Submissions\Models\Submission;
use FormSchema\Evaluation\EvaluationContext;
use FormSchema\Evaluation\Evaluator;
use FormSchema\Json\Json;
use Illuminate\Support\Carbon;
use stdClass;

/**
 * Builds the canonical render input for a submission (stored, server-evaluated data; built-ins as of
 * the submit time) or for the builder preview (an unsaved definition with sample data).
 */
final readonly class RenderInputFactory
{
    public function __construct(private Evaluator $evaluator) {}

    public function forSubmission(Submission $submission, DesignVersion $design): RenderInput
    {
        $organization = Organization::query()->findOrFail($submission->organization_id);
        $version = $submission->formVersion;
        $at = ($submission->submitted_at ?? $submission->updated_at)->copy()->setTimezone($organization->timezone);
        $submitter = User::query()->find($submission->submitted_by ?? $submission->created_by);
        $context = $this->context($at, $submitter, $organization, $version->version);
        // Stored data is authoritative (computed at submit); evaluation supplies element visibility.
        $result = $this->evaluator->evaluate($version->definition, $submission->data, $context);

        return new RenderInput(
            Json::copy($version->definition),
            Json::copy($submission->data),
            $result->visibility,
            Branding::forOrganization($organization),
            $design->designSettings(),
            new DocumentMeta(
                $organization->name, $submission->number, $submission->status->value,
                $submission->submitted_at?->copy()->setTimezone($organization->timezone)->format('Y-m-d\TH:i:sP'),
                $organization->timezone, $submission->form->key, $this->title($version->definition),
                $version->version, $version->id, $design->version, $design->id, DocumentRenderer::VERSION,
                organizationId: $organization->id,
            ),
            $this->assets($submission),
        );
    }

    public function forPreview(stdClass $definition, DesignSettings $design, ?DesignVersion $designVersion, Organization $organization, User $user): RenderInput
    {
        $now = Carbon::now()->setTimezone($organization->timezone);
        $result = $this->evaluator->evaluate($definition, new SampleData($now)->for($definition), $this->context($now, $user, $organization, null));

        return new RenderInput(
            Json::copy($definition),
            $result->data,
            $result->visibility,
            Branding::forOrganization($organization),
            $design,
            new DocumentMeta(
                $organization->name, (string) __('documents.render.preview_number'), 'draft', $now->format('Y-m-d\TH:i:sP'),
                $organization->timezone, DocumentTree::text($definition->key ?? ''), $this->title($definition),
                null, null, $designVersion?->version, $designVersion?->id, DocumentRenderer::VERSION,
                preview: true, organizationId: $organization->id,
            ),
            SampleData::assets(),
        );
    }

    private function context(Carbon $at, ?User $user, Organization $organization, ?int $formVersion): EvaluationContext
    {
        return new EvaluationContext(
            today: $at->format('Y-m-d'),
            now: $at->format('Y-m-d\TH:i:sP'),
            userName: $user?->name,
            userEmail: $user?->email,
            orgName: $organization->name,
            formVersion: $formVersion,
        );
    }

    private function title(stdClass $definition): string
    {
        return DocumentTree::text($definition->title ?? '');
    }

    /** @return array<string, RenderAsset> */
    private function assets(Submission $submission): array
    {
        $assets = [];
        foreach (Attachment::query()->where('submission_uuid', $submission->uuid)->orderBy('uuid')->get() as $attachment) {
            $assets[strtolower($attachment->uuid)] = new RenderAsset(
                $attachment->kind, $attachment->mime, $attachment->sha256, $attachment->disk, $attachment->path, name: $attachment->original_name,
            );
        }

        return $assets;
    }
}
