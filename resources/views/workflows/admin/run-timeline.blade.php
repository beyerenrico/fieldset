{{-- Per-step timeline of a workflow run (§8.3): status, attempts, log lines and error of every step. --}}
{{-- @var \App\Domain\Workflows\Models\WorkflowRun $run --}}
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
            <p class="mt-1 text-xs text-gray-500">
                {{ __('workflows.runs.attempts', ['count' => $step->attempts]) }}
                @if ($step->started_at) · {{ $step->started_at->toDateTimeString() }} @endif
                @if ($step->finished_at) → {{ $step->finished_at->toDateTimeString() }} @endif
            </p>
            @if ($step->error)
                <p class="mt-2 text-sm text-danger-600 dark:text-danger-400">{{ $step->error }}</p>
            @endif
            @if ($step->log === [])
                <p class="mt-2 text-xs text-gray-400">{{ __('workflows.runs.no_log') }}</p>
            @else
                <ul class="mt-2 space-y-1 text-xs">
                    @foreach ($step->log as $line)
                        <li @class(['text-danger-600 dark:text-danger-400' => $line['level'] === 'error', 'text-warning-600 dark:text-warning-400' => $line['level'] === 'warning'])>
                            <span class="font-mono text-gray-400">{{ \Illuminate\Support\Carbon::parse($line['at'])->toDateTimeString() }}</span>
                            {{ $line['message'] }}
                        </li>
                    @endforeach
                </ul>
            @endif
        </li>
    @endforeach
</ol>
