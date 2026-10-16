<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('identity.invitation.title') }} · {{ config('app.name') }}</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #f4f5f7; color: #111; margin: 0; }
        main { max-width: 28rem; margin: 4rem auto; background: #fff; padding: 2rem; border-radius: .75rem; box-shadow: 0 1px 3px rgba(0,0,0,.1); }
        label { display: block; font-weight: 600; margin: 1rem 0 .25rem; }
        input { width: 100%; box-sizing: border-box; padding: .75rem; font-size: 1rem; border: 1px solid #c4c8cf; border-radius: .5rem; }
        button { margin-top: 1.5rem; width: 100%; min-height: 44px; font-size: 1rem; font-weight: 600; color: #fff; background: #1d4ed8; border: 0; border-radius: .5rem; cursor: pointer; }
        .error { color: #b91c1c; font-size: .9rem; margin-top: .25rem; }
    </style>
</head>
<body>
<main>
    <h1>{{ __('identity.invitation.title') }}</h1>
    <p>{{ __('identity.invitation.body', ['organization' => $membership->organization->name]) }}</p>
    <form method="post" action="{{ request()->fullUrl() }}">
        @csrf
        @if ($needsPassword)
            <label for="password">{{ __('identity.invitation.password') }}</label>
            <input id="password" name="password" type="password" autocomplete="new-password" required>
            @error('password')<p class="error">{{ $message }}</p>@enderror
            <label for="password_confirmation">{{ __('identity.invitation.password_confirmation') }}</label>
            <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
        @endif
        <button type="submit">{{ __('identity.invitation.accept') }}</button>
    </form>
</main>
</body>
</html>
