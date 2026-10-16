{{-- Saved report result (M5-T03): one row per group plus the total; values are exact decimal strings formatted per locale. --}}
@php
    /** @var \App\Domain\Reports\Saved\ReportResult $result */
    $formatter = new \App\Domain\Submissions\Admin\ValueFormatter(app()->getLocale(), config('app.timezone'));
    $withMetric = $result->metric !== \App\Domain\Reports\Enums\ReportMetric::Count;
@endphp
<div class="fi-ta-ctn overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10" style="position: relative">
    @if ($result->rows === [])
        <p class="p-6 text-sm text-gray-500 dark:text-gray-400">{{ __('reports.result.empty') }}</p>
    @else
        <table class="w-full table-auto divide-y divide-gray-200 text-sm dark:divide-white/5">
            <thead>
                <tr>
                    <th scope="col" class="px-4 py-3 text-start font-semibold">{{ $result->groupLabel }}</th>
                    <th scope="col" class="px-4 py-3 text-end font-semibold">{{ __('reports.result.submissions') }}</th>
                    @if ($withMetric)
                        <th scope="col" class="px-4 py-3 text-end font-semibold">{{ $result->metric->label() }}: {{ $result->metricLabel }}</th>
                    @endif
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-white/5">
                @foreach ($result->rows as $row)
                    <tr>
                        <td class="px-4 py-2">{{ $row->label }}</td>
                        <td class="px-4 py-2 text-end tabular-nums">{{ $formatter->decimal((string) $row->submissions) }}</td>
                        @if ($withMetric)
                            <td class="px-4 py-2 text-end tabular-nums">{{ $result->display($row->value($result->metric), $formatter) }}</td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="font-semibold">
                    <th scope="row" class="px-4 py-3 text-start">{{ $result->total->label }}</th>
                    <td class="px-4 py-3 text-end tabular-nums">{{ $formatter->decimal((string) $result->total->submissions) }}</td>
                    @if ($withMetric)
                        <td class="px-4 py-3 text-end tabular-nums">{{ $result->display($result->total->value($result->metric), $formatter) }}</td>
                    @endif
                </tr>
            </tfoot>
        </table>
    @endif
</div>
