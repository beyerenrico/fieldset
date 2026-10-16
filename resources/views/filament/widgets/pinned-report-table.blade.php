{{-- Pinned saved report as a compact table (ADR 0034): the first rows, how many more, the total and a link to the full report. --}}
@php
    /**
     * @var \App\Domain\Reports\Saved\ReportResult $result
     * @var list<\App\Domain\Reports\Saved\ReportRow> $rows
     * @var \App\Domain\Submissions\Admin\ValueFormatter $formatter
     */
    $withMetric = $result->metric !== \App\Domain\Reports\Enums\ReportMetric::Count;
@endphp
<x-filament-widgets::widget class="fi-wi-pinned-report">
    <x-filament::section :heading="$heading" :description="$description">
        @if ($result->rows === [])
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('reports.result.empty') }}</p>
        @else
            <div class="overflow-x-auto" style="position: relative">
                <table class="w-full table-auto divide-y divide-gray-200 text-sm dark:divide-white/5">
                    <thead>
                        <tr>
                            <th scope="col" class="py-2 pe-3 text-start font-semibold">
                                @if ($result->groupBy === null)
                                    <span class="fi-sr-only">{{ $result->groupLabel }}</span>
                                @else
                                    {{ $result->groupLabel }}
                                @endif
                            </th>
                            <th scope="col" class="py-2 ps-3 text-end font-semibold">{{ __('reports.result.submissions') }}</th>
                            @if ($withMetric)
                                <th scope="col" class="py-2 ps-3 text-end font-semibold">{{ $result->metric->label() }}: {{ $result->metricLabel }}</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/5">
                        @foreach ($rows as $row)
                            <tr>
                                <td class="py-1.5 pe-3">{{ $row->label }}</td>
                                <td class="py-1.5 ps-3 text-end tabular-nums">{{ $formatter->decimal((string) $row->submissions) }}</td>
                                @if ($withMetric)
                                    <td class="py-1.5 ps-3 text-end tabular-nums">{{ $result->display($row->value($result->metric), $formatter) }}</td>
                                @endif
                            </tr>
                        @endforeach
                        @if ($more > 0)
                            <tr>
                                <td colspan="{{ $withMetric ? 3 : 2 }}" class="py-1.5 text-gray-500 dark:text-gray-400">{{ __('dashboard.pinned.more', ['count' => $more]) }}</td>
                            </tr>
                        @endif
                    </tbody>
                    <tfoot>
                        <tr class="font-semibold">
                            <th scope="row" class="py-2 pe-3 text-start">{{ $result->total->label }}</th>
                            <td class="py-2 ps-3 text-end tabular-nums">{{ $formatter->decimal((string) $result->total->submissions) }}</td>
                            @if ($withMetric)
                                <td class="py-2 ps-3 text-end tabular-nums">{{ $result->display($result->total->value($result->metric), $formatter) }}</td>
                            @endif
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
        <p class="mt-3 text-sm">
            <x-filament::link :href="$url">{{ __('dashboard.pinned.open') }}</x-filament::link>
        </p>
    </x-filament::section>
</x-filament-widgets::widget>
