@php
    /** @var \App\Filament\Admin\Resources\Forms\Pages\CanvasBuilder $this */
    // Livewire never morphs the wire:ignore root, so its content (and the not-free builderConfig()) is only
    // rendered on the first, non-Livewire request; later renders send an empty root.
    $initial = ! \Livewire\Livewire::isLivewireRequest();
    $config = $initial ? $this->builderConfig() : ['palette' => []];
    $strings = ['ui' => __('builder_v2'), 'lint' => __('forms.lint'), 'types' => __('forms.types')];
    $icons = collect($config['palette'])->flatMap(fn (array $group): array => array_column($group['items'], 'icon'))->unique()->values();
    $toolbar = [
        ['undo', 'heroicon-o-arrow-uturn-left', 'builder_v2.toolbar.undo', 'Ctrl+Z'],
        ['redo', 'heroicon-o-arrow-uturn-right', 'builder_v2.toolbar.redo', 'Ctrl+Shift+Z'],
        ['save', 'heroicon-o-cloud-arrow-up', 'builder_v2.toolbar.save', 'Ctrl+S'],
        ['publish', 'heroicon-o-rocket-launch', 'builder_v2.toolbar.publish', null],
        ['preview-pdf', 'heroicon-o-document-arrow-down', 'builder_v2.toolbar.preview_pdf', null],
        ['export', 'heroicon-o-arrow-down-tray', 'builder_v2.toolbar.export', null],
        ['preview-toggle', 'heroicon-o-device-tablet', 'builder_v2.toolbar.preview_toggle', null],
        ['json', 'heroicon-o-code-bracket', 'builder_v2.toolbar.json', null],
        ['classic', 'heroicon-o-queue-list', 'builder_v2.toolbar.classic', null],
    ];
@endphp
{{-- Builder v2 (ADR 0037). Livewire never renders the canvas: toolbar, palette and canvas live under one
     wire:ignore root driven by resources/builder-v2 (async-alpine loads it); the inspector beside it is morphed,
     and the preview after it has its own wire:ignore block (focus order: canvas, properties, preview).
     Plain bv2- CSS with Filament variables: no theme compiles Tailwind for our views (LEARNINGS #42). --}}
<x-filament-panels::page>
    @vite('resources/builder-v2/src/builder.css')
    <div class="bv2-layout">
        @if (! $initial)
            <div class="bv2-root" data-builder-root wire:ignore wire:key="builder-v2-root"></div>
        @else
        <div
            class="bv2-root"
            data-builder-root
            data-builder-sync-state="idle"
            data-builder-preview-size="phone"
            wire:ignore
            wire:key="builder-v2-root"
            x-load
            x-data="builderV2(@js($strings))"
            x-bind:data-builder-ready="ready ? '' : null"
        >
            {{-- async-alpine downloads the client before Alpine initialises this root. Registered with asyncData
                 instead of x-load-src: Vite app builds drop entry exports, so main.ts also sets window.FieldsetBuilderV2. --}}
            <script @if (filled($cspNonce = \Illuminate\Support\Facades\Vite::cspNonce())) nonce="{{ $cspNonce }}" @endif>
                (() => {
                    const register = () => window.Alpine.asyncData('builderV2', () =>
                        import(@js(\Illuminate\Support\Facades\Vite::asset('resources/builder-v2/src/main.ts')))
                            .then((module) => module.default ?? window.FieldsetBuilderV2));
                    window.Alpine?.asyncData ? register() : document.addEventListener('alpine:initializing', register, { once: true });
                })();
            </script>
            <script type="application/json" data-builder-config>@json($config)</script>
            <template data-builder-icons>
                @foreach ($icons as $icon)
                    <span data-icon="{{ $icon }}">@svg($icon, 'bv2-icon')</span>
                @endforeach
            </template>
            <p id="bv2-handle-help" class="bv2-sr-only">{{ __('builder_v2.canvas.handle_help') }}</p>
            <div class="bv2-sr-only" data-builder-live aria-live="polite" aria-atomic="true"></div>

            {{-- A plain group (no arrow-key toolbar pattern). Unavailable buttons are aria-disabled, so they keep focus
                 and explain themselves when used; the publish reason is the button's description. --}}
            <div class="bv2-toolbar" role="group" aria-label="{{ __('builder_v2.toolbar.label') }}">
                @foreach ($toolbar as [$command, $icon, $label, $shortcut])
                    <button
                        type="button"
                        class="bv2-btn"
                        data-builder-toolbar="{{ $command }}"
                        @if ($shortcut) aria-keyshortcuts="{{ str_replace('Ctrl', 'Control', $shortcut) }}" title="{{ __($label) }} ({{ $shortcut }})" @endif
                        @if ($command === 'preview-toggle') aria-pressed="false" @endif
                        @if (in_array($command, ['undo', 'redo'], true)) aria-disabled="true" @endif
                    >@svg($icon, 'bv2-icon')<span>{{ __($label) }}</span></button>
                @endforeach
                <span class="bv2-sr-only" id="bv2-publish-reason" data-builder-publish-reason></span>
                <span class="bv2-sync" data-builder-sync-label>{{ __('builder_v2.sync.idle') }}</span>
            </div>
            <div class="bv2-banner" data-builder-banner hidden></div>

            <section class="bv2-palette" aria-labelledby="bv2-palette-title">
                <h2 class="bv2-palette__title" id="bv2-palette-title">{{ __('builder_v2.palette.label') }}</h2>
                <div class="bv2-palette__groups" data-builder-palette></div>
            </section>

            <section class="bv2-canvas" aria-label="{{ __('builder_v2.canvas.section_label') }}">
                <div class="bv2-canvas__bar">
                    <button type="button" class="bv2-btn" data-builder-settings aria-pressed="false">@svg('heroicon-o-cog-6-tooth', 'bv2-icon')<span>{{ __('builder_v2.canvas.settings') }}</span></button>
                    <button type="button" class="bv2-btn" data-builder-goto-inspector aria-keyshortcuts="Alt+P" title="{{ __('builder_v2.canvas.goto_inspector') }} (Alt+P)">@svg('heroicon-o-adjustments-horizontal', 'bv2-icon')<span>{{ __('builder_v2.canvas.goto_inspector') }}</span></button>
                    <button type="button" class="bv2-btn" data-builder-help-toggle aria-expanded="false" aria-controls="bv2-help">@svg('heroicon-o-question-mark-circle', 'bv2-icon')<span>{{ __('builder_v2.help.toggle') }}</span></button>
                </div>
                <div class="bv2-help" id="bv2-help" data-builder-help role="region" aria-labelledby="bv2-help-title" hidden>
                    <h2 class="bv2-help__title" id="bv2-help-title">{{ __('builder_v2.help.title') }}</h2>
                    <dl class="bv2-help__list">
                        @foreach (__('builder_v2.help.actions') as $name => $action)
                            <div><dt>{{ __('builder_v2.help.keys.'.$name) }}</dt><dd>{{ $action }}</dd></div>
                        @endforeach
                    </dl>
                </div>
                <div class="bv2-lint" data-builder-lint role="region" aria-labelledby="bv2-lint-title">
                    <h2 class="bv2-lint__title" id="bv2-lint-title">{{ __('builder_v2.lint.label') }}: <span data-builder-lint-count data-count="0">{{ __('builder_v2.lint.ok') }}</span></h2>
                    <ul class="bv2-lint__list" data-builder-lint-list></ul>
                </div>
                <div class="bv2-tree" role="tree" aria-label="{{ __('builder_v2.canvas.label') }}" data-builder-tree data-builder-list data-builder-container="pages"></div>
                <button type="button" class="bv2-btn bv2-add-page" data-builder-add-page>@svg('heroicon-o-plus', 'bv2-icon')<span>{{ __('builder_v2.canvas.add_page') }}</span></button>
            </section>

            <dialog class="bv2-dialog" data-builder-json aria-labelledby="bv2-json-title">
                <h2 class="bv2-dialog__title" id="bv2-json-title">{{ __('builder_v2.json.title') }}</h2>
                <pre class="bv2-dialog__json" data-builder-json-content tabindex="0"></pre>
                <button type="button" class="bv2-btn" data-builder-json-close>{{ __('builder_v2.json.close') }}</button>
            </dialog>
        </div>
        @endif

        {{-- data-builder-inspected: the node the fields belong to (its id, "settings", or empty), updated with them. --}}
        <aside
            class="bv2-inspector"
            data-builder-inspector
            data-builder-inspected="{{ $this->inspectorKind === null ? '' : ($this->inspectorId ?? $this->inspectorKind) }}"
            aria-label="{{ __('builder_v2.inspector.label') }}"
        >
            {{ $this->inspector }}
        </aside>

        {{-- The preview follows the inspector in the focus order; its own wire:ignore block keeps the iframe alive.
             sandbox without allow-same-origin: the preview runs in an opaque origin (ADR 0040, QUESTIONS #10). --}}
        <div class="bv2-preview-block" wire:ignore wire:key="builder-v2-preview">
            <section class="bv2-preview" data-builder-preview-pane data-size="phone" aria-label="{{ __('builder_v2.preview.label') }}">
                <iframe
                    data-builder-preview
                    data-size="phone"
                    src="{{ url('/form-preview/') }}"
                    title="{{ __('builder_v2.preview.frame') }}"
                    sandbox="allow-scripts"
                ></iframe>
            </section>
        </div>
    </div>
</x-filament-panels::page>
