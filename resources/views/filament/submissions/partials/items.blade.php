@php
    /** @var list<array<string, mixed>> $items */
    // Consecutive fields share one description list; sections and repeater tables break the list.
    $runs = [];
    foreach ($items as $item) {
        $inline = in_array($item['kind'], ['field', 'media'], true);
        if ($inline && $runs !== [] && $runs[array_key_last($runs)]['inline']) {
            $runs[array_key_last($runs)]['items'][] = $item;
        } else {
            $runs[] = ['inline' => $inline, 'items' => [$item]];
        }
    }
@endphp
<div style="display:grid;gap:.75rem">
    @foreach ($runs as $run)
        @if ($run['inline'])
            <dl style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,16rem),1fr));gap:.75rem 1.5rem;margin:0">
                @foreach ($run['items'] as $item)
                    <div style="min-width:0">
                        <dt style="font-size:.8125rem;opacity:.7">{{ $item['label'] }}</dt>
                        <dd style="margin:.125rem 0 0;overflow-wrap:anywhere">@include('filament.submissions.partials.value', ['item' => $item])</dd>
                    </div>
                @endforeach
            </dl>
        @elseif ($run['items'][0]['kind'] === 'group')
            <div style="border-top:1px solid rgba(127,127,127,.25);padding-top:.5rem">
                @if ($run['items'][0]['label'] !== '')
                    <h4 style="margin:0 0 .5rem;font-size:.875rem;font-weight:600">{{ $run['items'][0]['label'] }}</h4>
                @endif
                @include('filament.submissions.partials.items', ['items' => $run['items'][0]['items']])
            </div>
        @else
            @include('filament.submissions.partials.table', ['item' => $run['items'][0]])
        @endif
    @endforeach
</div>
