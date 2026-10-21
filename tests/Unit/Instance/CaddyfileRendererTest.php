<?php

declare(strict_types=1);

use App\Domain\Instance\Compose\CaddyfileRenderer;
use App\Domain\Instance\Compose\EdgeSite;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Runtime\CatcherRoute;
use App\Domain\Instance\State\InstanceSettings;
use Tests\Support\Instance\ComposeFixtures;
use Tests\Support\Instance\DomainFixtures;

/*
 * Plan U8 (KTD10, R16, R66; ADR 0075): the edge's Caddyfile for domain mode, structured and quoted.
 */

function caddyfile(EdgeSite $site): string
{
    return (new CaddyfileRenderer)->render($site);
}

/** @return list<string> the lines of one block, from its opening line to its closing brace */
function caddyBlock(string $file, string $opening): array
{
    $lines = explode("\n", $file);
    $start = array_search($opening, $lines, true);
    expect($start)->not->toBeFalse("no line {$opening}");
    $indent = strlen($opening) - strlen(ltrim($opening));
    $block = [];
    foreach (array_slice($lines, (int) $start) as $line) {
        $block[] = $line;
        if ($line === str_repeat("\t", $indent).'}') {
            break;
        }
    }

    return $block;
}

it('renders the global options: no admin API, no autosave, Let\'s Encrypt production by default, no contact', function (): void {
    $file = caddyfile(new EdgeSite('forms.example.com'));

    expect($file)->toStartWith(CaddyfileRenderer::HEADER."\n{\n")
        ->and(caddyBlock($file, '{'))->toBe([
            '{',
            "\tadmin off",
            "\tpersist_config off",
            "\tacme_ca \"".InstanceSettings::ACME_PRODUCTION.'"',
            '}',
        ]);
});

it('sets the ACME contact, the directory and the custom root for the directory\'s HTTPS when configured', function (): void {
    $file = caddyfile(new EdgeSite('forms.example.com', 'https://pebble:14000/dir', 'ops@example.com', acmeCaRoot: true));

    expect(caddyBlock($file, '{'))->toBe([
        '{',
        "\tadmin off",
        "\tpersist_config off",
        "\temail \"ops@example.com\"",
        "\tacme_ca \"https://pebble:14000/dir\"",
        "\tacme_ca_root \"/etc/caddy/acme-ca-root.pem\"",
        '}',
    ]);
});

it('serves the domain with automatic HTTPS (no tls or redirect override) and proxies everything else to the app unchanged', function (): void {
    $file = caddyfile(new EdgeSite('forms.example.com'));
    $site = caddyBlock($file, '"forms.example.com" {');

    // Caddy sets X-Forwarded-For/-Proto/-Host itself but passes a client's X-Forwarded-Port on (ADR 0079).
    expect($site)->toContain("\thandle {")
        ->and(caddyBlock($file, "\t\treverse_proxy app:8080 {"))->toBe([
            "\t\treverse_proxy app:8080 {",
            "\t\t\theader_up -X-Forwarded-Port",
            "\t\t}",
        ])
        ->and(implode("\n", $site))->not->toContain('tls ')
        ->not->toContain('auto_https')
        ->not->toContain('header_down')
        ->not->toContain('encode')
        ->not->toContain('log');
});

it('answers the internal auth path with 404 from outside', function (): void {
    $file = caddyfile(new EdgeSite('forms.example.com'));

    expect(caddyBlock($file, "\thandle /_fsa/* {"))->toBe(["\thandle /_fsa/* {", "\t\trespond 404", "\t}"])
        ->and(CatcherRoute::AUTH_PATH)->toStartWith(CatcherRoute::INTERNAL_PREFIX);
});

it('puts /_mail/ behind forward_auth to the app, then Mailpit with the edge-injected credential (LEARNINGS 103)', function (): void {
    $file = caddyfile(new EdgeSite('forms.example.com'));

    expect($file)->toContain("\tredir /_mail /_mail/ 308")
        ->and(caddyBlock($file, "\t\tforward_auth app:8080 {"))->toBe([
            "\t\tforward_auth app:8080 {",
            "\t\t\turi \"/_fsa/catcher-auth\"",
            "\t\t\theader_up -Authorization",
            "\t\t\theader_up -Upgrade",
            "\t\t\theader_up -Connection",
            "\t\t\theader_up -X-Forwarded-Port",
            "\t\t}",
        ])
        // Mailpit never sees the operator's session (MFA'd /platform cookie) or the XSRF token (ADR 0079).
        ->and(caddyBlock($file, "\t\treverse_proxy mailpit:8025 {"))->toBe([
            "\t\treverse_proxy mailpit:8025 {",
            "\t\t\theader_up -Cookie",
            "\t\t\theader_up -X-XSRF-TOKEN",
            "\t\t\theader_up Authorization \"{env.FIELDSET_CATCHER_AUTHORIZATION}\"",
            "\t\t\tstream_timeout 5m",
            "\t\t}",
        ]);
});

it('hardens the catcher responses: same-origin framing for the Raw tab, nosniff, no-store, no referrer', function (): void {
    expect(caddyBlock(caddyfile(new EdgeSite('forms.example.com')), "\t\theader {"))->toBe([
        "\t\theader {",
        "\t\t\tdefer",
        "\t\t\tX-Frame-Options \"SAMEORIGIN\"",
        "\t\t\tX-Content-Type-Options \"nosniff\"",
        "\t\t\t+Content-Security-Policy \"frame-ancestors 'self'\"",
        "\t\t\tReferrer-Policy \"no-referrer\"",
        "\t\t\tCache-Control \"no-store\"",
        "\t\t}",
    ]);
});

it('leaves /_mail/ to the app when mail goes out over SMTP (no catcher)', function (): void {
    $file = caddyfile(new EdgeSite('forms.example.com', catcher: false));

    expect($file)->not->toContain('_mail')
        ->not->toContain('forward_auth')
        ->not->toContain('mailpit')
        ->not->toContain(CaddyfileRenderer::AUTHORIZATION_ENV)
        ->toContain("\thandle /_fsa/* {");
});

it('adds a loopback-only health site for the container healthcheck', function (): void {
    expect(caddyBlock(caddyfile(new EdgeSite('forms.example.com')), 'http://:8081 {'))->toBe([
        'http://:8081 {',
        "\tbind 127.0.0.1",
        "\trespond /healthz \"ok\" 200",
        '}',
    ]);
});

it('never writes the catcher password: only the env placeholder', function (): void {
    $file = caddyfile(EdgeSite::fromSettings(InstanceSettings::of(DomainFixtures::state())));

    expect($file)->not->toContain(ComposeFixtures::CATCHER_PASSWORD)
        ->not->toContain(base64_encode('fsa:'.ComposeFixtures::CATCHER_PASSWORD))
        ->toContain('{env.'.CaddyfileRenderer::AUTHORIZATION_ENV.'}');
});

it('renders from the instance settings', function (): void {
    $state = DomainFixtures::state(['acme_email' => 'ops@example.com', 'acme_ca' => InstanceSettings::ACME_STAGING, 'acme_ca_root' => str_repeat('a', 64)]);
    $file = caddyfile(EdgeSite::fromSettings(InstanceSettings::of($state)));

    expect($file)->toContain("\n\"forms.example.com\" {\n")
        ->toContain('email "ops@example.com"')
        ->toContain('acme_ca "'.InstanceSettings::ACME_STAGING.'"')
        ->toContain('acme_ca_root "/etc/caddy/acme-ca-root.pem"');
});

it('is deterministic', function (): void {
    $site = new EdgeSite('forms.example.com', 'https://pebble:14000/dir', 'ops@example.com', true);

    expect(caddyfile($site))->toBe(caddyfile($site));
});

it('refuses values that could end a token or line, or reach substitution and placeholders', function (string $field, string $payload): void {
    $site = match ($field) {
        'domain' => new EdgeSite($payload),
        'email' => new EdgeSite('forms.example.com', acmeEmail: $payload),
        default => new EdgeSite('forms.example.com', acmeCa: $payload),
    };

    try {
        caddyfile($site);
        $this->fail('rendering should fail');
    } catch (InstanceException $e) {
        expect($e->errorCode)->toBe('unsafe_caddyfile')->and($e->exitCode)->toBe(ExitCode::InternalError);
    }
})->with([
    'line break with a new site' => ['domain', "forms.example.com {\n}\nevil.example.com"],
    'closing quote' => ['domain', 'forms.example.com" {'],
    'env substitution' => ['domain', '{$FIELDSET_CATCHER_AUTHORIZATION}.example.com'],
    'runtime placeholder' => ['email', '{env.FIELDSET_CATCHER_AUTHORIZATION}@example.com'],
    'backslash escape' => ['email', 'ops\\"@example.com'],
    'backtick token' => ['acme', 'https://ca.example.com/`dir`'],
    'carriage return' => ['acme', "https://ca.example.com/dir\r\nadmin :2019"],
    'null byte' => ['domain', "forms.example.com\0"],
    'invalid utf-8' => ['email', "ops\xC3@example.com"],
]);

it('keeps a hash or a heredoc marker inside its quoted token', function (): void {
    $file = caddyfile(new EdgeSite('forms.example.com', 'https://ca.example.com/dir#<<EOF'));

    expect($file)->toContain("\tacme_ca \"https://ca.example.com/dir#<<EOF\"");
});

it('quotes plain values and refuses unsafe ones on its own', function (): void {
    expect(CaddyfileRenderer::quote("frame-ancestors 'self'"))->toBe("\"frame-ancestors 'self'\"")
        ->and(fn () => CaddyfileRenderer::quote('a}b'))->toThrow(InstanceException::class)
        ->and(fn () => CaddyfileRenderer::quote("a\tb"))->toThrow(InstanceException::class);
});
