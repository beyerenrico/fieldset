<?php

declare(strict_types=1);

use Tests\Support\Docker\EvidenceRedactor;

/*
 * M10 gate: journey evidence is committed, so InstanceJourney::evidence() redacts what must never be in the
 * repository (cookie values, XSRF tokens, CSP nonces, sign-in links, signatures and tokens) wherever it sits.
 */

it('redacts cookie values in Set-Cookie and Cookie headers but keeps names and attributes', function (): void {
    $redacted = EvidenceRedactor::transcript(['admin_login' => [
        'Set-Cookie' => 'XSRF-TOKEN=eyJpdiI6IkVrT2s2dEk0Zm1v%3D%3D; expires=Sun, 18 Oct 2026 10:26:08 GMT; Max-Age=7200; path=/; secure; samesite=lax, __Host-form-schema-session=eyJpdiI6Ik1Wb2Rx%3D%3D; expires=Sun, 18 Oct 2026 10:26:08 GMT; Max-Age=7200; path=/; secure; httponly; samesite=lax',
        'Cache-Control' => 'max-age=0, must-revalidate, no-cache, no-store, private',
    ], 'request' => ['cookie' => '__Host-form-schema-session=abc123; XSRF-TOKEN=def456']]);

    expect($redacted['admin_login']['Set-Cookie'])->toBe('XSRF-TOKEN=<redacted>; expires=Sun, 18 Oct 2026 10:26:08 GMT; Max-Age=7200; path=/; secure; samesite=lax, __Host-form-schema-session=<redacted>; expires=Sun, 18 Oct 2026 10:26:08 GMT; Max-Age=7200; path=/; secure; httponly; samesite=lax')
        ->and($redacted['admin_login']['Cache-Control'])->toBe('max-age=0, must-revalidate, no-cache, no-store, private')
        ->and($redacted['request']['cookie'])->toBe('__Host-form-schema-session=<redacted>; XSRF-TOKEN=<redacted>');
});

it('redacts raw header lines, header lists and XSRF/CSRF header values', function (): void {
    $redacted = EvidenceRedactor::transcript([
        'headers' => ['HTTP/2 200', 'set-cookie: XSRF-TOKEN=eyJ0eXAi; path=/; secure', 'x-xsrf-token: eyJ0eXAi', 'content-type: text/html'],
        'Set-Cookie' => ['a=1; path=/', 'b=2; path=/'],
        'X-CSRF-TOKEN' => 'secret-token-value',
    ]);

    expect($redacted['headers'])->toBe(['HTTP/2 200', 'set-cookie: XSRF-TOKEN=<redacted>; path=/; secure', 'x-xsrf-token: <redacted>', 'content-type: text/html'])
        ->and($redacted['Set-Cookie'])->toBe(['a=<redacted>; path=/', 'b=<redacted>; path=/'])
        ->and($redacted['X-CSRF-TOKEN'])->toBe('<redacted>');
});

it('redacts CSP nonces, sign-in links, signatures and token fields, and leaves the rest alone', function (): void {
    $redacted = EvidenceRedactor::transcript([
        'Content-Security-Policy' => "default-src 'self'; script-src 'self' 'nonce-nzuKRfHfnJpybU1E2FfJeh4Ij6AuaTG8g2gVw4WV' 'unsafe-eval'",
        'stdout' => ['data' => ['sign_in_link' => ['url' => 'https://forms.example/login-link#k3y.v4lue-_~', 'delivered' => 'stdout']]],
        'signed' => 'https://forms.example/invitations/7?expires=1760000000&signature=0f9a8b7c6d5e4f3a2b1c',
        'smoke_token' => 'c0ffee00c0ffee00c0ffee00c0ffee00',
        'api' => ['access_token' => 'fsa_0123456789abcdef', 'token_type' => 'Bearer'],
        'secrets' => ['database_password' => '[redacted]', 'valkey_password' => 'plain-leak'],
        'Location' => 'https://fsa-test-j02.test/admin/login',
        'audits' => '1',
    ]);

    expect($redacted['Content-Security-Policy'])->toBe("default-src 'self'; script-src 'self' 'nonce-<redacted>' 'unsafe-eval'")
        ->and($redacted['stdout']['data']['sign_in_link']['url'])->toBe('https://forms.example/login-link#<redacted>')
        ->and($redacted['signed'])->toBe('https://forms.example/invitations/7?expires=1760000000&signature=<redacted>')
        ->and($redacted['smoke_token'])->toBe('<redacted>')
        ->and($redacted['api'])->toBe(['access_token' => '<redacted>', 'token_type' => 'Bearer'])
        // fsa's own redaction stays visible as such.
        ->and($redacted['secrets'])->toBe(['database_password' => '[redacted]', 'valkey_password' => '<redacted>'])
        ->and($redacted['Location'])->toBe('https://fsa-test-j02.test/admin/login')
        ->and($redacted['audits'])->toBe('1');
});

it('masks the value of every entry in an envelope\'s secrets list, also inside JSON strings (M11 gate A9)', function (): void {
    $envelope = ['status' => 'ok', 'secrets' => [
        ['name' => 'demo_password:owner@demo.test', 'value' => 'Xk4v-pR9q-Lm2w', 'expires_at' => null],
        ['name' => 'sign_in_link', 'value' => 'http://localhost:8080/login-link#abc', 'expires_at' => '2026-10-18T22:35:32Z'],
        ['name' => 'sign_in_link', 'written_to' => '<work>/owner-link.json', 'expires_at' => null],
    ]];

    $redacted = EvidenceRedactor::transcript([
        'stdout' => $envelope,
        'raw' => json_encode($envelope, JSON_UNESCAPED_SLASHES),
        'ndjson' => "{\"phase\":\"start\",\"message\":\"Starting\"}\n".json_encode(['data' => ['nested' => $envelope]], JSON_UNESCAPED_SLASHES),
        'config_show' => ['secrets' => ['app_key' => 'base64:c2VjcmV0', 'database_password' => '[redacted]']],
    ]);

    $raw = json_decode($redacted['raw'], true);
    $lines = explode("\n", $redacted['ndjson']);
    expect(array_column($redacted['stdout']['secrets'], 'value'))->toBe(['<redacted>', '<redacted>'])
        ->and($redacted['stdout']['secrets'][0]['name'])->toBe('demo_password:owner@demo.test')
        ->and($redacted['stdout']['secrets'][2])->toBe(['name' => 'sign_in_link', 'written_to' => '<work>/owner-link.json', 'expires_at' => null])
        ->and(array_column($raw['secrets'], 'value'))->toBe(['<redacted>', '<redacted>'])
        ->and(json_decode($lines[0], true))->toBe(['phase' => 'start', 'message' => 'Starting'])
        ->and(array_column(json_decode($lines[1], true)['data']['nested']['secrets'], 'value'))->toBe(['<redacted>', '<redacted>'])
        ->and($redacted['config_show']['secrets'])->toBe(['app_key' => '<redacted>', 'database_password' => '[redacted]'])
        ->and(json_encode($redacted))->not->toContain('Xk4v-pR9q-Lm2w')->not->toContain('c2VjcmV0');
});
