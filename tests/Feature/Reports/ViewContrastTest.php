<?php

declare(strict_types=1);

use App\Domain\Workflows\Models\WorkflowRun;
use App\Domain\Workflows\Models\WorkflowStepRun;

/* Review M5 U7/U9: custom admin views stay readable without a Filament theme (LEARNINGS #42). */

it('gives errors and warnings in the run timeline a dark-mode colour with enough contrast (U7)', function (): void {
    $step = new WorkflowStepRun;
    $step->forceFill(['index' => 0, 'type' => 'webhook', 'status' => 'failed', 'attempts' => 2, 'error' => 'Webhook answered 500',
        'log' => [['at' => '2026-10-17T10:00:00+00:00', 'level' => 'warning', 'message' => 'Retrying'], ['at' => '2026-10-17T10:01:00+00:00', 'level' => 'error', 'message' => 'Gave up']]]);
    $run = new WorkflowRun;
    $run->setRelation('stepRuns', collect([$step]));

    $html = view('workflows.admin.run-timeline', ['run' => $run])->render();

    expect($html)->toContain('color:light-dark(var(--danger-700, #b91c1c), var(--danger-400, #f87171))')
        ->toContain('color:light-dark(var(--warning-700, #a16207), var(--warning-400, #facc15))')
        ->toContain('Webhook answered 500');
});

it('marks the selected preview size with aria-pressed and an inline style, not an uncompiled utility (U9)', function (): void {
    $source = (string) file_get_contents(resource_path('views/filament/forms/preview.blade.php'));

    expect($source)->toContain("x-bind:aria-pressed=\"size === 'phone' ? 'true' : 'false'\"")
        ->toContain("x-bind:aria-pressed=\"size === 'tablet' ? 'true' : 'false'\"")
        ->not->toContain('ring-2');
});
