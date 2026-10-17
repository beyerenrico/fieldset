{{--
    Our layout for every error page (overrides Laravel's errors::minimal, which the stock 401–503 views extend):
    `<html lang>` follows the app locale; an optional `actions` section shows a way onward. Inline styles only
    (no compiled Tailwind for our views, LEARNINGS #42; the HTML CSP allows inline styles, ADR 0040).
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>@yield('title')</title>
        <style>
            body { margin: 0; font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; color: #111827; background: #fff; line-height: 1.5; }
            main { display: flex; min-height: 100vh; align-items: center; justify-content: center; padding: 1.5rem; box-sizing: border-box; }
            .error { max-width: 36rem; }
            .error-head { display: flex; align-items: center; gap: 1rem; font-size: 1.125rem; }
            .error-code { margin: 0; padding-right: 1rem; border-right: 1px solid #9ca3af; font-size: 1.125rem; font-weight: 400; }
            .error-actions { margin-top: 1.5rem; }
            .error-actions a { color: #1d4ed8; text-decoration: underline; }
            .error-actions a:focus-visible { outline: 2px solid #1d4ed8; outline-offset: 2px; }
        </style>
    </head>
    <body>
        <main>
            <div class="error">
                <div class="error-head">
                    <h1 class="error-code">@yield('code')</h1>
                    <div>@yield('message')</div>
                </div>
                @hasSection('actions')
                    <p class="error-actions">@yield('actions')</p>
                @endif
            </div>
        </main>
    </body>
</html>
