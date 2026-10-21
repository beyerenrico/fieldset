<?php

declare(strict_types=1);

namespace App\Domain\Platform\Instance\Restore;

use App\Domain\Audit\ErrorText;
use App\Domain\Documents\Designs\DesignResolver;
use App\Domain\Documents\Rendering\DocumentRenderer;
use App\Domain\Documents\Rendering\RenderedDocument;
use App\Domain\Documents\Rendering\RenderInputFactory;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Submissions\Models\Submission;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * The drill's document check (R41, plan U17, ADR 0085): the newest submitted submission of the drill database is rendered
 * through the app's own renderer (design, input, HTML, Gotenberg) into a temporary directory, never the storage volume,
 * and nothing is recorded (no rendition row, no audit entry). The file is removed afterwards; the result says whether a
 * PDF came back, its size and pages.
 */
final readonly class DrillRender
{
    public function __construct(
        private DesignResolver $designs,
        private RenderInputFactory $inputs,
        private DocumentRenderer $renderer,
        private TenantContext $tenant,
    ) {}

    /** @return array{status: string, submission: string|null, bytes: int|null, pages: int|null, message: string|null} */
    public function run(): array
    {
        $row = DB::table('submissions')->where('status', '!=', 'draft')->orderByDesc('created_at')->first(['uuid', 'organization_id']);
        if ($row === null) {
            return ['status' => 'skipped', 'submission' => null, 'bytes' => null, 'pages' => null, 'message' => 'The backup holds no submitted submission to render.'];
        }
        $directory = sys_get_temp_dir().'/fieldset-drill-'.bin2hex(random_bytes(6));
        $file = $directory.'/document.pdf';

        try {
            $document = $this->tenant->run((int) $row->organization_id, fn (): RenderedDocument => $this->render((string) $row->uuid));
            if (! @mkdir($directory, 0700) || file_put_contents($file, $document->pdf) !== strlen($document->pdf)) {
                throw new RuntimeException('The rendered PDF could not be written to a temporary directory.');
            }

            return ['status' => 'rendered', 'submission' => (string) $row->uuid, 'bytes' => (int) filesize($file), 'pages' => $document->pageCount, 'message' => null];
        } catch (Throwable $e) {
            return ['status' => 'failed', 'submission' => (string) $row->uuid, 'bytes' => null, 'pages' => null, 'message' => ErrorText::scrub(($e->getPrevious() ?? $e)->getMessage())];
        } finally {
            @unlink($file);
            @rmdir($directory);
        }
    }

    private function render(string $uuid): RenderedDocument
    {
        $submission = Submission::query()->findOrFail($uuid);

        return $this->renderer->render($this->inputs->forSubmission($submission, $this->designs->forSubmission($submission)));
    }
}
