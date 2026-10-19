@php
    /**
     * Phone trust page of local-CA mode (R19, ADR 0057, ADR 0082). Standalone: over plain HTTP the edge forwards only
     * this page and the root certificate, so CSS and script are inline and every link to the certificate is relative.
     *
     * @var \App\Domain\Instance\Runtime\PhoneTrust $trust
     * @var list<string> $fingerprintGroups
     * @var string $qrCode
     * @var bool $checkable
     * @var string $trustPageUrl
     */
    use App\Domain\Instance\Runtime\PhoneTrustRoute;
    $nonce = \Illuminate\Support\Facades\Vite::cspNonce();
    $locale = app()->getLocale();
    $fieldAppUrl = $trust->fieldAppUrl();
    $rootName = $trust->root->subject();
@endphp
<!doctype html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta name="referrer" content="no-referrer">
    <meta name="color-scheme" content="light">
    <title>{{ __('phone_trust.title') }} · {{ config('app.name') }}</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        html { -webkit-text-size-adjust: 100%; }
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; font-size: 1rem; line-height: 1.5; color: #111827; background: #f3f4f6; }
        .top, main { max-width: 40rem; margin: 0 auto; }
        .top { display: flex; justify-content: flex-end; padding: .25rem 1rem 0; }
        .langs { display: flex; gap: .25rem; margin: 0; padding: 0; list-style: none; }
        .langs a { display: inline-flex; align-items: center; justify-content: center; min-width: 44px; min-height: 44px; padding: 0 .75rem; border-radius: .5rem; color: #1d4ed8; font-weight: 600; }
        .langs a[aria-current] { color: #111827; background: #e5e7eb; text-decoration: none; }
        main { padding: 1rem 1rem 2rem; background: #fff; }
        @media (min-width: 42rem) { main { margin-top: .5rem; margin-bottom: 2rem; padding: 2rem; border-radius: .75rem; box-shadow: 0 1px 3px rgba(0, 0, 0, .1); } }
        h1 { margin: 0 0 1rem; font-size: 1.5rem; line-height: 1.25; }
        h2 { margin: 2rem 0 .5rem; font-size: 1.2rem; line-height: 1.3; }
        p, li, dd, figcaption { overflow-wrap: anywhere; }
        ol { margin: .5rem 0; padding-left: 1.5rem; }
        li + li { margin-top: .5rem; }
        dl { margin: 0; }
        dt { margin-top: .75rem; font-weight: 600; }
        dd { margin: .25rem 0 0; }
        code { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: .95rem; }
        .fingerprint { display: block; padding: .75rem; border-radius: .5rem; background: #f3f4f6; line-height: 1.7; }
        .fingerprint span { display: inline-block; }
        .note { color: #4b5563; }
        .callout { margin: 1rem 0; padding: .75rem 1rem; border-left: 4px solid #b45309; border-radius: .25rem; background: #fffbeb; color: #78350f; }
        .button { display: inline-flex; align-items: center; justify-content: center; min-height: 44px; margin: .25rem 0; padding: .5rem 1.25rem; border: 2px solid #1d4ed8; border-radius: .5rem; background: #1d4ed8; color: #fff; font: inherit; font-weight: 600; text-decoration: none; cursor: pointer; }
        .button.secondary { background: #fff; color: #1d4ed8; }
        a { color: #1d4ed8; }
        a:focus-visible, button:focus-visible { outline: 3px solid #111827; outline-offset: 2px; }
        [data-trust-state="trusted"] { color: #166534; font-weight: 600; }
        [data-trust-state="untrusted"] { color: #991b1b; font-weight: 600; }
        .qr { margin: 1rem 0 0; }
        .qr svg { display: block; width: 100%; max-width: 232px; height: auto; }
        [hidden] { display: none !important; }
    </style>
</head>
<body>
<header class="top">
    <nav aria-label="{{ __('phone_trust.language') }}">
        <ul class="langs">
            <li><a href="?lang=de" lang="de" hreflang="de" @if ($locale === 'de') aria-current="true" @endif>Deutsch</a></li>
            <li><a href="?lang=en" lang="en" hreflang="en" @if ($locale === 'en') aria-current="true" @endif>English</a></li>
        </ul>
    </nav>
</header>
<main>
    <h1>{{ __('phone_trust.title') }}</h1>
    <p>{{ __('phone_trust.intro') }}</p>
    <p class="note">{{ __('phone_trust.demo') }}</p>

    <section aria-labelledby="addresses-heading">
        <h2 id="addresses-heading">{{ __('phone_trust.addresses.heading') }}</h2>
        <dl>
            <dt>{{ __('phone_trust.addresses.instance') }}</dt>
            <dd><code data-instance-url>{{ $trust->instanceUrl }}</code></dd>
            <dt>{{ __('phone_trust.addresses.field_app') }}</dt>
            <dd><code data-field-app-url>{{ $fieldAppUrl }}</code></dd>
        </dl>
    </section>

    <section aria-labelledby="certificate-heading">
        <h2 id="certificate-heading">{{ __('phone_trust.certificate.heading') }}</h2>
        <p><a class="button" href="{{ PhoneTrustRoute::ROOT_PATH }}" data-root-download>{{ __('phone_trust.certificate.download') }}</a></p>
        <dl>
            <dt>{{ __('phone_trust.certificate.name') }}</dt>
            <dd data-root-name>{{ $rootName }}</dd>
            <dt>{{ __('phone_trust.certificate.fingerprint') }}</dt>
            <dd><code class="fingerprint" data-fingerprint="{{ $trust->root->fingerprint() }}">@foreach ($fingerprintGroups as $group)<span>{{ $group }}</span>@endforeach</code></dd>
        </dl>
        <p class="callout">{{ __('phone_trust.certificate.compare') }}</p>
    </section>

    <section aria-labelledby="ios-heading">
        <h2 id="ios-heading">{{ __('phone_trust.ios.heading') }}</h2>
        <ol data-steps="ios">
            @foreach (__('phone_trust.ios.steps', ['root' => $rootName]) as $step)
                <li>{{ $step }}</li>
            @endforeach
        </ol>
    </section>

    <section aria-labelledby="android-heading">
        <h2 id="android-heading">{{ __('phone_trust.android.heading') }}</h2>
        <p class="callout" data-tunnel-recommendation>{{ __('phone_trust.android.tunnel') }}</p>
        <ol data-steps="android">
            @foreach (__('phone_trust.android.steps') as $step)
                <li>{{ $step }}</li>
            @endforeach
        </ol>
        <p class="note">{{ __('phone_trust.android.menus') }}</p>
    </section>

    <section id="trust-check" aria-labelledby="check-heading" data-probe="{{ $trust->probeUrl() }}"
             data-checking="{{ __('phone_trust.check.checking') }}" data-trusted="{{ __('phone_trust.check.trusted') }}"
             data-untrusted="{{ __('phone_trust.check.untrusted') }}">
        <h2 id="check-heading">{{ __('phone_trust.check.heading') }}</h2>
        @if ($checkable)
            <div id="trust-js" hidden>
                <p id="trust-status" role="status" aria-live="polite" data-trust-state="checking">{{ __('phone_trust.check.checking') }}</p>
                <p id="trust-open" hidden><a class="button" href="{{ $fieldAppUrl }}">{{ __('phone_trust.check.open') }}</a></p>
                <button type="button" id="trust-again" class="button secondary">{{ __('phone_trust.check.again') }}</button>
            </div>
            <noscript>
                <p>{{ __('phone_trust.check.noscript') }}</p>
                <p><a class="button" href="{{ $fieldAppUrl }}">{{ __('phone_trust.check.open') }}</a></p>
            </noscript>
        @else
            <p>{{ __('phone_trust.check.elsewhere', ['url' => $trustPageUrl]) }}</p>
            <p><a class="button" href="{{ $fieldAppUrl }}">{{ __('phone_trust.check.open') }}</a></p>
        @endif
    </section>

    <section aria-labelledby="qr-heading">
        <h2 id="qr-heading">{{ __('phone_trust.qr.heading') }}</h2>
        <p>{{ __('phone_trust.qr.body') }}</p>
        <figure class="qr">
            <div role="img" aria-label="{{ __('phone_trust.qr.label', ['url' => $fieldAppUrl]) }}" data-qr>{!! $qrCode !!}</div>
            <figcaption><code>{{ $fieldAppUrl }}</code></figcaption>
        </figure>
    </section>

    <section aria-labelledby="change-heading">
        <h2 id="change-heading">{{ __('phone_trust.change.heading') }}</h2>
        <p class="callout" data-url-change-warning>{{ __('phone_trust.change.body') }}</p>
    </section>

    <section aria-labelledby="remove-heading">
        <h2 id="remove-heading">{{ __('phone_trust.remove.heading') }}</h2>
        <p class="callout">{{ __('phone_trust.remove.warning') }}</p>
        <ul>
            <li>{{ __('phone_trust.remove.ios') }}</li>
            <li>{{ __('phone_trust.remove.android') }}</li>
        </ul>
    </section>
</main>
{{--
    Trust check: load the instance's favicon over HTTPS. It loads only when the phone accepts the instance's
    certificate (a TLS failure fires the image's error event), which then reveals the Field App link. Each attempt
    settles once; a newer attempt makes older ones irrelevant.
--}}
@if ($checkable)
<script @if (filled($nonce)) nonce="{{ $nonce }}" @endif>
    (function () {
        var section = document.getElementById('trust-check');
        var box = document.getElementById('trust-js');
        var status = document.getElementById('trust-status');
        var open = document.getElementById('trust-open');
        var again = document.getElementById('trust-again');
        var attempt = 0;
        function show(state) {
            status.setAttribute('data-trust-state', state);
            status.textContent = section.getAttribute('data-' + state);
            open.hidden = state !== 'trusted';
            again.hidden = state === 'trusted';
        }
        function check() {
            var current = ++attempt;
            var settled = false;
            var image = new Image();
            var timer = window.setTimeout(function () { done(false); }, 10000);
            function done(trusted) {
                if (settled || current !== attempt) {
                    return;
                }
                settled = true;
                window.clearTimeout(timer);
                show(trusted ? 'trusted' : 'untrusted');
            }
            image.onload = function () { done(true); };
            image.onerror = function () { done(false); };
            show('checking');
            image.src = section.getAttribute('data-probe') + '?check=' + Date.now();
        }
        again.addEventListener('click', check);
        box.hidden = false;
        check();
    })();
</script>
@endif
</body>
</html>
