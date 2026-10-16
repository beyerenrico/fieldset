@php
    $lint = $this->lint ?? ['schema_errors' => [], 'findings' => []];
    $problems = count($lint['schema_errors']) + count($lint['findings']);
@endphp
<div wire:poll.20s="autosave">
    @if ($problems === 0)
        <x-filament::badge color="success" icon="heroicon-o-check-circle">{{ __('forms.builder.lint_ok') }}</x-filament::badge>
    @else
        <x-filament::section :heading="__('forms.builder.lint_title').' ('.$problems.')'" icon="heroicon-o-exclamation-triangle" icon-color="danger" collapsible compact>
            <ul style="display:grid;gap:.35rem;margin:0;padding:0;list-style:none">
                @foreach ($lint['schema_errors'] as $error)
                    <li><x-filament::badge color="danger">{{ __('forms.lint.schema') }}</x-filament::badge> <code>{{ $error['path'] }}</code> — {{ $error['message'] }}</li>
                @endforeach
                @foreach ($lint['findings'] as $finding)
                    <li>
                        <x-filament::badge :color="$finding['severity'] === 'error' ? 'danger' : 'warning'">{{ __('forms.lint.'.$finding['code']) }}</x-filament::badge>
                        <code>{{ $finding['path'] }}</code>
                    </li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif
</div>
