@php
    /** @var \App\Filament\Admin\Resources\Forms\Pages\EditForm $this */
    $definition = $this->previewDefinitionJson();
@endphp
{{-- Live preview (BUILD_PROMPT §8.2): the same renderer as the Field App, fed the UNSAVED definition via
     an origin-checked postMessage. The iframe page has no service worker and no storage (§6.1). --}}
<div
    wire:key="form-preview-{{ md5($definition) }}"
    x-data="{
        size: 'phone',
        definition: @js($definition),
        send() {
            const frame = this.$refs.frame;
            if (frame && frame.contentWindow) {
                frame.contentWindow.postMessage({ type: 'form-schema-preview', definition: JSON.parse(this.definition) }, window.location.origin);
            }
        },
        init() {
            window.addEventListener('message', (event) => {
                if (event.origin === window.location.origin && event.data && event.data.type === 'form-schema-preview-ready') {
                    this.send();
                }
            });
        },
    }"
    x-init="$nextTick(() => send())"
>
    <div style="display:flex;gap:.5rem;margin-bottom:.75rem">
        <x-filament::button size="sm" color="gray" x-on:click="size = 'phone'" x-bind:class="size === 'phone' ? 'ring-2' : ''">{{ __('forms.preview.phone') }}</x-filament::button>
        <x-filament::button size="sm" color="gray" x-on:click="size = 'tablet'" x-bind:class="size === 'tablet' ? 'ring-2' : ''">{{ __('forms.preview.tablet') }}</x-filament::button>
    </div>
    <iframe
        x-ref="frame"
        src="{{ url('/form-preview/') }}"
        title="{{ __('forms.preview.title') }}"
        x-on:load="send()"
        x-bind:style="size === 'phone' ? 'width:390px;height:760px' : 'width:820px;height:760px'"
        style="width:390px;height:760px;border:1px solid #d1d5db;border-radius:1rem;background:#fff;max-width:100%"
        sandbox="allow-scripts allow-same-origin"
    ></iframe>
</div>
