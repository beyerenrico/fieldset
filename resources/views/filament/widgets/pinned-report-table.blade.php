{{-- Pinned saved report as a compact table (ADR 0034): the first rows, how many more, the total and a link to the full report. --}}
@php
    /**
     * @var \App\Domain\Reports\Saved\ReportResult $result
     * @var list<\App\Domain\Reports\Saved\ReportRow> $rows
     * @var \App\Domain\Submissions\Admin\ValueFormatter $formatter
     */
    $withMetric = $result->metric !== \App\Domain\Reports\Enums\ReportMetric::Count;
    // Inline styles: Filament's compiled theme does not contain arbitrary Tailwind utilities.
    $cell = 'padding:.375rem .75rem;border-bottom:1px solid rgba(127,127,127,.2)';
    $num = $cell.';text-align:end;font-variant-numeric:tabular-nums';
    $head = 'padding:.5rem .75rem;border-bottom:1px solid rgba(127,127,127,.35);font-weight:600';
    $muted = 'opacity:.8';
@endphp
<x-filament-widgets::widget class="fi-wi-pinned-report">
    <x-filament::section :heading="$heading" :description="$description">
        @if ($result->rows === [])
            <p style="font-size:.875rem;{{ $muted }}">{{ __('reports.result.empty') }}</p>
        @else
            <div style="position:relative;overflow-x:auto">
                <table style="width:100%;border-collapse:collapse;font-size:.875rem">
                    <thead>
                        <tr>
                            <th scope="col" style="{{ $head }};text-align:start">
                                @if ($result->groupBy === null)
                                    <span class="fi-sr-only">{{ $result->groupLabel }}</span>
                                @else
                                    {{ $result->groupLabel }}
                                @endif
                            </th>
                            <th scope="col" style="{{ $head }};text-align:end">{{ __('reports.result.submissions') }}</th>
                            @if ($withMetric)
                                <th scope="col" style="{{ $head }};text-align:end">{{ $result->metric->label() }}: {{ $result->metricLabel }}</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                <td style="{{ $cell }}">{{ $row->label }}</td>
                                <td style="{{ $num }}">{{ $formatter->decimal((string) $row->submissions) }}</td>
                                @if ($withMetric)
                                    <td style="{{ $num }}">{{ $result->display($row->value($result->metric), $formatter) }}</td>
                                @endif
                            </tr>
                        @endforeach
                        @if ($more > 0)
                            <tr>
                                <td colspan="{{ $withMetric ? 3 : 2 }}" style="{{ $cell }};{{ $muted }}">{{ __('dashboard.pinned.more', ['count' => $more]) }}</td>
                            </tr>
                        @endif
                    </tbody>
                    <tfoot>
                        <tr style="font-weight:600">
                            <th scope="row" style="padding:.5rem .75rem;text-align:start">{{ $result->total->label }}</th>
                            <td style="padding:.5rem .75rem;text-align:end;font-variant-numeric:tabular-nums">{{ $formatter->decimal((string) $result->total->submissions) }}</td>
                            @if ($withMetric)
                                <td style="padding:.5rem .75rem;text-align:end;font-variant-numeric:tabular-nums">{{ $result->display($result->total->value($result->metric), $formatter) }}</td>
                            @endif
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
        <p style="margin-top:.75rem;font-size:.875rem">
            <x-filament::link :href="$url">{{ __('dashboard.pinned.open') }}</x-filament::link>
        </p>
    </x-filament::section>
</x-filament-widgets::widget>
