@php /** @var array{label: string, columns: list<string>, rows: list<list<array<string, mixed>>>} $item */ @endphp
<div style="position:relative;overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:.875rem">
        <caption style="padding-bottom:.25rem;text-align:start;font-weight:600">
            {{ $item['label'] }} <span style="font-weight:400;opacity:.7">({{ __('inbox.detail.rows', ['count' => count($item['rows'])]) }})</span>
        </caption>
        <thead>
            <tr>
                <th scope="col" style="padding:.375rem .5rem;border-bottom:1px solid rgba(127,127,127,.35);text-align:start">
                    <span style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap">{{ __('inbox.detail.row') }}</span>#
                </th>
                @foreach ($item['columns'] as $column)
                    <th scope="col" style="padding:.375rem .5rem;border-bottom:1px solid rgba(127,127,127,.35);text-align:start;font-weight:600">{{ $column }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($item['rows'] as $row)
                <tr>
                    <td style="padding:.375rem .5rem;border-bottom:1px solid rgba(127,127,127,.2);opacity:.7">{{ $loop->iteration }}</td>
                    @foreach ($row as $cell)
                        <td style="padding:.375rem .5rem;border-bottom:1px solid rgba(127,127,127,.2);vertical-align:top">
                            @if ($cell['kind'] === 'table')
                                @include('filament.submissions.partials.table', ['item' => $cell])
                            @else
                                @include('filament.submissions.partials.value', ['item' => $cell])
                            @endif
                        </td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($item['columns']) + 1 }}" style="padding:.375rem .5rem">—</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
