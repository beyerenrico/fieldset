<?php

declare(strict_types=1);

use Illuminate\Support\Arr;

/* Gate review M7, FS-admin items 5–7 and 10–12: the texts the review asked for, in English and German. */

it('words the review texts as asked', function (string $key, string $en, string $de, array $params = []): void {
    expect(__('sso_admin.'.$key, $params, 'en'))->toBe($en)
        ->and(__('sso_admin.'.$key, $params, 'de'))->toBe($de);
})->with([
    'test sign-in succeeded' => ['test_result.succeeded', 'Test sign-in succeeded as a@b.example. Groups received: crew.',
        'Testanmeldung erfolgreich als a@b.example. Empfangene Gruppen: crew.', ['email' => 'a@b.example', 'groups' => 'crew']],
    'test sign-in failed' => ['test_result.failed', 'Test sign-in failed: X', 'Testanmeldung fehlgeschlagen: X', ['reason' => 'X']],
    'self-lockout' => ['enforcement.errors.self_lockout',
        'You would lock yourself out. Choose yourself as break-glass Owner, or sign out and sign in once with single sign-on (a test sign-in does not count).',
        'Sie würden sich selbst aussperren. Wählen Sie sich selbst als Notfall-Inhaber oder melden Sie sich ab und einmal per Single Sign-on an (eine Testanmeldung zählt nicht).'],
    'confirmation heading' => ['enforcement.confirm_heading', 'Enforce single sign-on?', 'Single Sign-on erzwingen?'],
    'confirmation body' => ['enforcement.confirm_body',
        'Everyone with an address at acme.example can then sign in only through Keycloak. Only Berta keeps password sign-in for emergencies.',
        'Danach können sich alle mit einer Adresse unter acme.example nur noch über Keycloak anmelden. Nur Berta behält die Passwort-Anmeldung für Notfälle.',
        ['domains' => 'acme.example', 'provider' => 'Keycloak', 'owner' => 'Berta']],
    'invalid client' => ['status.error_reasons.invalid_client',
        'The identity provider rejected the client ID or secret. Check both and save again.',
        'Der Identitätsanbieter hat Client-ID oder Client-Geheimnis abgelehnt. Prüfen Sie beide Angaben und speichern Sie erneut.'],
    'TXT note' => ['domains.dns_provider_note',
        'Some DNS providers add your domain automatically. Then enter only “_form-schema-app-verification” as the name.',
        'Manche DNS-Anbieter ergänzen die Domain automatisch. Tragen Sie dann als Namen nur „_form-schema-app-verification“ ein.'],
    'protocol help' => ['provider.help.type',
        'Choose OpenID Connect for Microsoft Entra ID, Google Workspace, Keycloak and most modern providers; SAML 2.0 only if your provider offers nothing else.',
        'Wählen Sie OpenID Connect für Microsoft Entra ID, Google Workspace, Keycloak und die meisten aktuellen Anbieter; SAML 2.0 nur, wenn Ihr Anbieter nichts anderes anbietet.'],
    'claim label' => ['mappings.claim', 'Attribute name (claim)', 'Attributname (Claim)'],
]);

it('explains the issuer without the word and every reason of the last error', function (): void {
    foreach (['en', 'de'] as $locale) {
        expect(mb_strtolower((string) __('sso_admin.provider.help.issuer', [], $locale)))->not->toContain('issuer');
        foreach (['discovery', 'jwks', 'metadata', 'issuer', 'tenant', 'audience', 'signature', 'saml_signature', 'saml_issuer',
            'invalid_client', 'config', 'blocked_host', 'token_endpoint', 'other'] as $reason) {
            expect(__('sso_admin.status.error_reasons.'.$reason, [], $locale))->not->toStartWith('sso_admin.');
        }
    }
});

it('calls the Owner role by its German name in the SSO texts', function (): void {
    $german = implode("\n", Arr::flatten(require lang_path('de/sso_admin.php')));

    expect(__('roles.owner', [], 'de'))->toBe('Inhaber')
        ->and($german)->not->toContain('Owner')
        ->and($german)->toContain('Notfall-Inhaber');
});
