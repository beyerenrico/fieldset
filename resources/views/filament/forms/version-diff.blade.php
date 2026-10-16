@php /** @var list<\App\Domain\Forms\Schema\DiffEntry> $entries */ @endphp
@if ($entries === [])
    <p>{{ __('forms.versions.no_changes') }}</p>
@else
    <ul style="display:grid;gap:.4rem;margin:0;padding:0;list-style:none">
        @foreach ($entries as $entry)
            <li>
                <x-filament::badge :color="['added' => 'success', 'removed' => 'danger', 'changed' => 'warning', 'moved' => 'gray'][$entry->change]">
                    {{ __('forms.versions.changes.'.$entry->change) }}
                </x-filament::badge>
                <strong>{{ $entry->label !== '' ? $entry->label : $entry->key }}</strong>
                <code>{{ $entry->key }}</code> · {{ __('forms.types.'.$entry->type) }}
                @if ($entry->properties !== [])
                    — {{ implode(', ', $entry->properties) }}
                @endif
            </li>
        @endforeach
    </ul>
@endif
