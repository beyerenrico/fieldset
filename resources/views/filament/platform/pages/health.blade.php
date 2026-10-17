@php
    /** @var \App\Filament\Platform\Pages\Health $this */
    $results = $this->results();
@endphp
{{-- Operator health (§8.12, ADR 0041). Filament components and inline styles only: no theme compiles
     Tailwind classes for our views (LEARNINGS #42), and the panel CSP allows no inline scripts. --}}
<x-filament-panels::page>
    <div style="display:grid;gap:1rem;grid-template-columns:repeat(auto-fill,minmax(18rem,1fr))">
        @foreach ($results as $result)
            <x-filament::section data-health-check="{{ $result->key }}" data-health-status="{{ $result->status->value }}">
                <x-slot name="heading">{{ __('platform.health.checks.'.$result->key) }}</x-slot>
                <x-slot name="afterHeader">
                    <x-filament::badge :color="$result->status->color()">{{ __('platform.health.statuses.'.$result->status->value) }}</x-filament::badge>
                </x-slot>
                <p style="margin:0 0 .5rem">{{ $result->summary }}</p>
                @if ($result->details !== [])
                    <dl style="display:grid;grid-template-columns:auto 1fr;gap:.25rem .75rem;margin:0;font-size:.875rem">
                        @foreach ($result->details as $label => $value)
                            <dt style="opacity:.75">{{ $label }}</dt>
                            <dd style="margin:0;font-variant-numeric:tabular-nums;word-break:break-all">{{ $value }}</dd>
                        @endforeach
                    </dl>
                @endif
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>
