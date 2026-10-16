{{-- Notification mail (§8.9): "open" button and the preferences hint, appended to the branded body.
     Everything here is escaped; $url is a safe http(s) URL (NotificationRenderer::safeUrl). --}}
@php /** @var \App\Domain\Mail\Mailables\MailLayout|null $layout */ @endphp
@if ($url !== null)
<p style="margin:24px 0;">
<a href="{{ $url }}" style="display:inline-block;padding:10px 18px;border-radius:6px;background-color:{{ $layout?->primaryColor ?? '#2563eb' }};color:{{ $layout?->onPrimary ?? '#ffffff' }};font-weight:600;text-decoration:none;">{{ __('notifications.open') }}</a>
</p>
@endif
<p style="margin:16px 0 0;font-size:12px;color:#71717a;">{{ __('notifications.mail.preferences_hint') }}</p>
