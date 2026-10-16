{{-- Per-step timeline of a workflow run (§8.3): status, attempts, log lines and error of every step. --}}
{{-- Times in the viewer's display time zone; secondary text meets 4.5:1 contrast (gray-600 / dark gray-300). --}}
{{-- @var \App\Domain\Workflows\Models\WorkflowRun $run --}}
@php
    $tz = \App\Filament\Admin\Resources\Submissions\SubmissionResource::displayTimezone();
@endphp
<ol class="space-y-4">
    @foreach ($run->stepRuns as $step)
        @php
            $type = \App\Domain\Workflows\Enums\StepType::tryFrom($step->type);
        @endphp
        <li class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <span class="font-medium">{{ $step->index + 1 }}. {{ $type?->label() ?? $step->type }}</span>
                <x-filament::badge :color="$step->status->color()">{{ $step->status->label() }}</x-filament::badge>
            </div>
            <p class="mt-1 text-xs text-gray-600 dark:text-gray-300">
                {{ __('workflows.runs.attempts', ['count' => $step->attempts]) }}
                @if ($step->started_at) · {{ $step->started_at->copy()->setTimezone($tz)->toDateTimeString() }} @endif
                @if ($step->finished_at) → {{ $step->finished_at->copy()->setTimezone($tz)->toDateTimeString() }} @endif
            </p>
            @if ($step->error)
                <p class="mt-2 text-sm text-danger-700 dark:text-danger-400">{{ $step->error }}</p>
            @endif
            @if ($step->log === [])
                <p class="mt-2 text-xs text-gray-600 dark:text-gray-300">{{ __('workflows.runs.no_log') }}</p>
            @else
                <ul class="mt-2 space-y-1 text-xs">
                    @foreach ($step->log as $line)
                        <li @class(['text-danger-700 dark:text-danger-400' => $line['level'] === 'error', 'text-warning-700 dark:text-warning-400' => $line['level'] === 'warning'])>
                            <span class="font-mono text-gray-600 dark:text-gray-300">{{ \Illuminate\Support\Carbon::parse($line['at'])->setTimezone($tz)->toDateTimeString() }}</span>
                            {{ $line['message'] }}
                        </li>
                    @endforeach
                </ul>
            @endif
        </li>
    @endforeach
</ol>
