<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Steps;

use App\Domain\Documents\Designs\Actions\CreateDesign;
use App\Domain\Documents\Designs\DesignResolver;
use App\Domain\Documents\Models\Design;
use App\Domain\Documents\Models\DesignVersion;
use App\Domain\Documents\Models\Rendition;
use App\Domain\Documents\Rendering\RenderBudgetExceededException;
use App\Domain\Documents\Renditions\RenderSubmission;
use App\Domain\Submissions\Models\Submission;
use App\Domain\Workflows\Engine\StepContext;
use App\Domain\Workflows\Engine\StepFailedException;
use App\Domain\Workflows\Engine\StepResult;
use App\Domain\Workflows\Models\WorkflowStepRun;
use Illuminate\Support\Str;

/**
 * `generate_document`: a new rendition with the chosen design (`design_key`; empty = the form's design),
 * using that design's current version. The step output records the rendition and design version; the
 * render request id is kept on the step, so a retried step never creates a second rendition.
 */
final readonly class GenerateDocumentStep implements StepHandler
{
    public function __construct(private RenderSubmission $render, private DesignResolver $designs) {}

    public function execute(StepContext $context): StepResult
    {
        $submission = $context->requireSubmission();
        $design = $this->designVersion($context->string('design_key'), $submission);
        try {
            $rendition = $this->render->handle($submission, Rendition::REASON_WORKFLOW, null, $this->requestId($context->step), $design);
        } catch (RenderBudgetExceededException $e) {
            throw new StepFailedException($e->getMessage(), 0, $e);
        }
        $design->loadMissing('design');

        return StepResult::completed(
            __('workflows.log.document_generated', ['design' => $design->design->name, 'version' => $design->version]),
            [
                'rendition_id' => $rendition->id, 'rendition_uuid' => $rendition->uuid, 'design_key' => $design->design->key,
                'design_version_id' => $design->id, 'design_version' => $design->version,
            ],
        );
    }

    private function designVersion(?string $key, Submission $submission): DesignVersion
    {
        if ($key === null) {
            return $this->designs->forDefinition($submission->formVersion->definition);
        }
        $design = $key === CreateDesign::DEFAULT_KEY ? $this->designs->defaultDesign() : Design::query()->where('key', $key)->first();
        if (! $design instanceof Design) {
            throw new StepFailedException(__('workflows.errors.design_missing', ['key' => $key]));
        }

        return DesignResolver::usable($design) ?? throw new StepFailedException(__('workflows.errors.design_retired', ['key' => $key]));
    }

    private function requestId(WorkflowStepRun $step): string
    {
        $existing = $step->output['request_id'] ?? null;
        if (is_string($existing)) {
            return $existing;
        }
        $id = (string) Str::uuid7();
        $step->mergeOutput(['request_id' => $id]);
        $step->save();

        return $id;
    }
}
