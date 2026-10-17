@php
    /** @var \App\Domain\Identity\LoginLinks\LoginLinkState $state */
    use App\Domain\Identity\LoginLinks\LoginLinkState;
    $pending = $state === LoginLinkState::Valid;
    $recoverable = in_array($state, [LoginLinkState::Used, LoginLinkState::Expired, LoginLinkState::RateLimited, LoginLinkState::Invalid], true);
    $nonce = \Illuminate\Support\Facades\Vite::cspNonce();
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta name="referrer" content="no-referrer">
    <title>{{ __('login_link.title') }} · {{ config('app.name') }}</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #f4f5f7; color: #111; margin: 0; }
        main { max-width: 32rem; margin: 4rem auto; background: #fff; padding: 2rem; border-radius: .75rem; box-shadow: 0 1px 3px rgba(0,0,0,.1); }
        h1 { font-size: 1.5rem; margin-top: 0; }
        code { display: block; margin: .5rem 0; padding: .75rem; background: #1f2937; color: #f9fafb; border-radius: .5rem; font-size: 1rem; overflow-x: auto; }
        .hint { color: #4b5563; font-size: .9rem; }
        button { margin-top: 1.5rem; width: 100%; min-height: 44px; font-size: 1rem; font-weight: 600; color: #fff; background: #1d4ed8; border: 0; border-radius: .5rem; cursor: pointer; }
        a { color: #1d4ed8; }
        [hidden] { display: none !important; }
    </style>
</head>
<body>
<main data-login-link-state="{{ $state->value }}">
    @if ($pending)
        <section id="login-link-pending">
            <h1>{{ __('login_link.states.valid.heading') }}</h1>
            <p>{{ __('login_link.states.valid.body') }}</p>
            <noscript><p>{{ __('login_link.noscript') }}</p></noscript>
        </section>
        <section id="login-link-missing" hidden>
            <h1>{{ __('login_link.states.invalid.heading') }}</h1>
            <p>{{ __('login_link.states.invalid.body') }}</p>
            @include('login-link.recovery')
        </section>
    @else
        <h1>{{ __('login_link.states.'.$state->value.'.heading') }}</h1>
        <p>{{ __('login_link.states.'.$state->value.'.body') }}</p>
        @if ($recoverable)
            @include('login-link.recovery')
        @else
            <p><a href="{{ url('/admin/login') }}">{{ __('login_link.login') }}</a></p>
        @endif
    @endif
    <form id="login-link-form" method="post" action="{{ route('login-link.consume') }}" hidden>
        @csrf
        <input type="hidden" name="token" value="">
        <button type="submit">{{ __('login_link.continue') }}</button>
    </form>
</main>
{{--
    The token is in the fragment, which never leaves the browser: move it into the form, drop it from the address
    bar and POST it. A new link pasted into a tab that already shows this page only changes the fragment (no page
    load), so a hashchange submits it too.
--}}
<script @if (filled($nonce)) nonce="{{ $nonce }}" @endif>
    (function () {
        var pattern = /^[A-Za-z0-9]{32,128}$/;
        var form = document.getElementById('login-link-form');
        function takeToken() {
            var token = window.location.hash.slice(1);
            if (window.history && window.history.replaceState) {
                window.history.replaceState(null, '', window.location.pathname + window.location.search);
            }
            return token;
        }
        function submit(token) {
            form.elements.token.value = token;
            form.submit();
        }
        window.addEventListener('hashchange', function () {
            var token = takeToken();
            if (pattern.test(token)) {
                submit(token);
            }
        });
        if (!document.getElementById('login-link-pending')) {
            return;
        }
        var token = takeToken();
        if (!pattern.test(token)) {
            document.getElementById('login-link-pending').hidden = true;
            document.getElementById('login-link-missing').hidden = false;
            return;
        }
        form.hidden = false; // the button stays as a fallback while the POST is under way
        submit(token);
    })();
</script>
</body>
</html>
