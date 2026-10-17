{{-- Builder v2 (ADR 0037). Livewire never renders the canvas: palette, canvas and preview live under one
     wire:ignore root driven by resources/builder-v2; only the inspector below is morphed. --}}
<x-filament-panels::page>
    @vite('resources/builder-v2/src/builder.css')
    <div
        data-builder-root
        wire:ignore
        wire:key="builder-v2-root"
        x-load
        x-load-src="{{ \Illuminate\Support\Facades\Vite::asset('resources/builder-v2/src/main.ts') }}"
        x-data="builderV2()"
        x-bind:data-builder-ready="ready ? '' : null"
    >
        <script type="application/json" data-builder-config>@json($this->builderConfig())</script>
    </div>
    <div data-builder-inspector>
        {{ $this->inspector }}
    </div>
</x-filament-panels::page>
