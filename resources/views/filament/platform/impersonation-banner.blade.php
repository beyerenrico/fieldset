@php
    /** @var string $operator */ /** @var string $target */ /** @var string $until */ /** @var int $minutes */ /** @var string $exitUrl */
@endphp
{{-- Shown on every panel page while an operator impersonates a member (ADR 0041). A plain POST form with
     CSRF: no inline script (panel CSP), inline styles (no compiled Tailwind for our views, LEARNINGS #42).
     White on #92400e is 7:1. --}}
<div
    role="region"
    aria-label="{{ __('platform.impersonation.banner_label') }}"
    data-platform-impersonation-banner
    style="position:sticky;top:0;z-index:60;display:flex;flex-wrap:wrap;gap:.5rem 1rem;align-items:center;justify-content:center;padding:.5rem 1rem;background:#92400e;color:#fff;font-size:.875rem;line-height:1.4"
>
    <span>{{ __('platform.impersonation.banner', ['target' => $target, 'operator' => $operator, 'until' => $until, 'minutes' => $minutes]) }}</span>
    <form method="POST" action="{{ $exitUrl }}" style="margin:0">
        @csrf
        <button
            type="submit"
            data-platform-impersonation-exit
            style="padding:.25rem .75rem;border:1px solid #fff;border-radius:.375rem;background:#fff;color:#92400e;font-weight:600;cursor:pointer"
        >{{ __('platform.impersonation.exit') }}</button>
    </form>
</div>
