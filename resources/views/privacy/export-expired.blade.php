{{-- Expired or pruned data export download (ADR 0039): a friendly page with status 410. --}}
@php /** @var int $days */ @endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('privacy.exports.expired.title') }}</title>
    <style>
        :root { color-scheme: light dark; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; font-family: system-ui, sans-serif;
            background: light-dark(#f4f4f5, #18181b); color: light-dark(#18181b, #f4f4f5); }
        main { max-width: 32rem; margin: 1rem; padding: 2rem; border-radius: .75rem; background: light-dark(#ffffff, #27272a);
            box-shadow: 0 1px 3px rgba(0, 0, 0, .1); }
        h1 { margin: 0 0 .75rem; font-size: 1.25rem; }
        p { margin: 0; line-height: 1.5; }
    </style>
</head>
<body>
<main>
    <h1>{{ __('privacy.exports.expired.title') }}</h1>
    <p>{{ __('privacy.exports.expired.body', ['days' => $days]) }}</p>
</main>
</body>
</html>
