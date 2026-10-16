{{-- Saved report result (M5-T03): one row per group plus the total; values are exact decimal strings formatted per locale. --}}
@php
    /** @var \App\Domain\Reports\Saved\ReportResult $result */
    $formatter = new \App\Domain\Submissions\Admin\ValueFormatter(app()->getLocale(), config('app.timezone'));
    $withMetric = $result->metric !== \App\Domain\Reports\Enums\ReportMetric::Count;
    // Inline styles: the panel has no custom theme, so arbitrary Tailwind utilities are not compiled.
    $head = 'padding:.75rem 1rem;border-bottom:1px solid rgba(127,127,127,.35);font-weight:600';
    $cell = 'padding:.5rem 1rem;border-bottom:1px solid rgba(127,127,127,.2)';
    $num = 'text-align:end;font-variant-numeric:tabular-nums';
@endphp
<div class="fi-ta-ctn" style="position:relative;overflow-x:auto">
    @if ($result->rows === [])
        <p style="padding:1.5rem;font-size:.875rem;opacity:.8">{{ __('reports.result.empty') }}</p>
    @else
        <table style="width:100%;border-collapse:collapse;font-size:.875rem">
            <thead>
                <tr>
                    <th scope="col" style="{{ $head }};text-align:start">{{ $result->groupLabel }}</th>
                    <th scope="col" style="{{ $head }};text-align:end">{{ __('reports.result.submissions') }}</th>
                    @if ($withMetric)
                        <th scope="col" style="{{ $head }};text-align:end">{{ $result->metric->label() }}: {{ $result->metricLabel }}</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @foreach ($result->rows as $row)
                    <tr>
                        <td style="{{ $cell }}">{{ $row->label }}</td>
                        <td style="{{ $cell }};{{ $num }}">{{ $formatter->decimal((string) $row->submissions) }}</td>
                        @if ($withMetric)
                            <td style="{{ $cell }};{{ $num }}">{{ $result->display($row->value($result->metric), $formatter) }}</td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr style="font-weight:600">
                    <th scope="row" style="padding:.75rem 1rem;text-align:start">{{ $result->total->label }}</th>
                    <td style="padding:.75rem 1rem;{{ $num }}">{{ $formatter->decimal((string) $result->total->submissions) }}</td>
                    @if ($withMetric)
                        <td style="padding:.75rem 1rem;{{ $num }}">{{ $result->display($result->total->value($result->metric), $formatter) }}</td>
                    @endif
                </tr>
            </tfoot>
        </table>
    @endif
</div>
