{{-- Per-step timeline of a workflow run (§8.3): status, attempts, log lines and error of every step. --}}
{{-- Times in the viewer's display time zone; secondary text uses inherited colour at reduced opacity. --}}
{{-- @var \App\Domain\Workflows\Models\WorkflowRun $run --}}
@php
    $tz = \App\Filament\Admin\Resources\Submissions\SubmissionResource::displayTimezone();
    // Inline styles: the panel has no custom theme, so arbitrary Tailwind utilities are not compiled.
    $muted = 'opacity:.85';
    // light-dark(): the 700 shades on light, the 400 shades on Filament's dark background (≥ 4.5:1 both; `.dark` sets color-scheme).
    $danger = 'color:light-dark(var(--danger-700, #b91c1c), var(--danger-400, #f87171))';
    $warning = 'color:light-dark(var(--warning-700, #a16207), var(--warning-400, #facc15))';
@endphp
<ol style="display:grid;gap:1rem;list-style:none;margin:0;padding:0">
    @foreach ($run->stepRuns as $step)
        @php
            $type = \App\Domain\Workflows\Enums\StepType::tryFrom($step->type);
        @endphp
        <li style="border:1px solid rgba(127,127,127,.3);border-radius:.5rem;padding:.75rem">
            <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.5rem">
                <span style="font-weight:500">{{ $step->index + 1 }}. {{ $type?->label() ?? $step->type }}</span>
                <x-filament::badge :color="$step->status->color()">{{ $step->status->label() }}</x-filament::badge>
            </div>
            <p style="margin-top:.25rem;font-size:.75rem;{{ $muted }}">
                {{ __('workflows.runs.attempts', ['count' => $step->attempts]) }}
                @if ($step->started_at) · {{ $step->started_at->copy()->setTimezone($tz)->toDateTimeString() }} @endif
                @if ($step->finished_at) → {{ $step->finished_at->copy()->setTimezone($tz)->toDateTimeString() }} @endif
            </p>
            @if ($step->error)
                <p style="margin-top:.5rem;font-size:.875rem;{{ $danger }}">{{ $step->error }}</p>
            @endif
            @if ($step->log === [])
                <p style="margin-top:.5rem;font-size:.75rem;{{ $muted }}">{{ __('workflows.runs.no_log') }}</p>
            @else
                <ul style="margin:.5rem 0 0;padding:0;list-style:none;display:grid;gap:.25rem;font-size:.75rem">
                    @foreach ($step->log as $line)
                        <li @style([$danger => $line['level'] === 'error', $warning => $line['level'] === 'warning'])>
                            <span style="font-family:ui-monospace,monospace;{{ $muted }}">{{ \Illuminate\Support\Carbon::parse($line['at'])->setTimezone($tz)->toDateTimeString() }}</span>
                            {{ $line['message'] }}
                        </li>
                    @endforeach
                </ul>
            @endif
        </li>
    @endforeach
</ol>
