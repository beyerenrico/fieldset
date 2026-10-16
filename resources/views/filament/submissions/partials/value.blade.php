@php /** @var array<string, mixed> $item */ @endphp
@if ($item['kind'] === 'media')
    <span style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:flex-end">
        @forelse ($item['files'] as $file)
            @if ($file['missing'])
                <x-filament::badge color="warning">{{ __('inbox.detail.missing_file') }}</x-filament::badge>
            @elseif ($file['image'])
                <a href="{{ $file['url'] }}" target="_blank" rel="noopener" title="{{ __('inbox.detail.open_file', ['name' => $file['name']]) }}">
                    <img src="{{ $file['url'] }}" alt="{{ $item['label'] }}: {{ $file['name'] }}" loading="lazy"
                         style="display:block;max-width:10rem;max-height:6rem;border:1px solid rgba(127,127,127,.3);border-radius:.375rem;background:#fff">
                </a>
            @else
                <a href="{{ $file['url'] }}" style="text-decoration:underline">{{ $file['name'] }}</a>
            @endif
        @empty
            —
        @endforelse
    </span>
    @if ($item['caption'] !== null)
        <span style="display:block;font-size:.8125rem;opacity:.75">{{ $item['caption'] }}</span>
    @endif
@else
    <span style="white-space:pre-line">{{ $item['value'] }}</span>
@endif
