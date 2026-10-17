{{-- History section at the end of a key record page (render hook, ADR 0038). --}}
@php /** @var string $component @var array<string, string> $params @var string $key */ @endphp
<section class="fi-audit-history" style="margin-top: 1.5rem;">
    @livewire($component, $params, key($key))
</section>
