@php
    /** @var string $variant */ /** @var bool $operator */ /** @var string $command */ /** @var string|null $mailSettingsUrl */
@endphp
{{-- Capture mode (R24/R59, ADR 0080): non-dismissible, at the top of every admin page of an organization. A named
     region (landmark), inline styles (no compiled Tailwind for our views, LEARNINGS #42), not sticky so it never
     covers the impersonation banner. White on #1e3a8a is 10.4:1; the command's backdrop keeps it above 7:1. --}}
<div
    role="region"
    aria-label="{{ __('capture.label') }}"
    data-capture-banner="{{ $variant }}"
    style="display:flex;flex-wrap:wrap;gap:.25rem 1rem;align-items:center;justify-content:center;padding:.625rem 1rem;background:#1e3a8a;color:#fff;font-size:.875rem;line-height:1.5;text-align:center"
>
    <strong>{{ __("capture.{$variant}.title") }}</strong>
    <span>{{ __("capture.{$variant}.body") }}</span>
    @if ($operator)
        <span data-capture-banner-command>{{ __('capture.operator') }}
            <code style="padding:.125rem .375rem;border-radius:.25rem;background:rgba(255,255,255,.15);color:#fff;font-size:.8125rem;overflow-wrap:anywhere">{{ $command }}</code>
        </span>
    @else
        <span data-capture-banner-ask>{{ __('capture.ask_owner') }}</span>
    @endif
    @if ($mailSettingsUrl !== null)
        <a href="{{ $mailSettingsUrl }}" data-capture-banner-settings style="color:#fff;font-weight:600;text-decoration:underline">{{ __('capture.mail_settings') }}</a>
    @endif
</div>
