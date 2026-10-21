<?php

declare(strict_types=1);

// Phone trust page of local-CA mode (R19, ADR 0057, ADR 0082). :root is the CA's name as the phone lists it.
return [
    'title' => 'Trust this instance on your phone',
    'language' => 'Language',
    'intro' => 'This instance uses its own certificate authority (CA) for HTTPS on your network. Once your phone trusts this CA, it can open the Field App over HTTPS, which offline use and "Add to Home Screen" need.',
    'demo' => 'Meant for demos and tests on your own network. For real use, run the instance under a domain with a public certificate.',
    'addresses' => [
        'heading' => 'Addresses',
        'instance' => 'HTTPS address of this instance',
        'field_app' => 'Field App',
    ],
    'certificate' => [
        'heading' => 'Root certificate',
        'download' => 'Download root certificate',
        'name' => 'Name on the phone',
        'fingerprint' => 'SHA-256 fingerprint',
        'compare' => 'Before you trust the certificate, compare this fingerprint with the one fsa printed when it set up phone HTTPS (fieldset status shows it too). Continue only if both match: this page also comes over plain HTTP, so someone on the same network could swap the certificate.',
    ],
    'ios' => [
        'heading' => 'iPhone and iPad (iOS)',
        'steps' => [
            'Open this page in Safari. Other browsers on iOS cannot install profiles.',
            'Tap "Download root certificate" and allow the download. iOS reports "Profile Downloaded".',
            'Open Settings → General → VPN & Device Management, tap the downloaded profile under "Downloaded Profile", then tap Install and enter your passcode.',
            'Open Settings → General → About → Certificate Trust Settings and turn on full trust for ":root".',
            'Come back to this page: the check below shows whether your phone trusts the instance.',
        ],
    ],
    'android' => [
        'heading' => 'Android',
        'steps' => [
            'Tap "Download root certificate".',
            'Open Settings → Security (or Security & privacy) → More security settings → Encryption & credentials.',
            'Tap Install a certificate → CA certificate, confirm "Install anyway" and choose the downloaded file.',
            'Come back to this page: the check below shows whether your phone trusts the instance.',
        ],
        'menus' => 'The menu names differ between manufacturers. If you cannot find them, search the settings for "CA certificate".',
        'tunnel' => 'Many Android versions and browsers do not trust user-installed CAs for installed web apps. On Android, the quick tunnel is the recommended way. It cannot run next to this CA, so on the computer that runs this instance first turn the CA off with fieldset config set phone-https off --yes, then run fieldset tunnel start.',
    ],
    'check' => [
        'heading' => 'Check',
        'checking' => 'Checking whether this phone trusts the instance …',
        'trusted' => 'Your phone trusts this instance.',
        'untrusted' => 'Your phone does not trust this instance yet. Install the certificate, turn on full trust and check again.',
        'again' => 'Check again',
        'open' => 'Open the Field App',
        'noscript' => 'Without JavaScript this page cannot check the trust. Once the certificate is installed and trusted, open the Field App:',
        'elsewhere' => 'The check works on the phone only. Open :url on the phone; once the certificate is trusted, the Field App opens at:',
    ],
    'qr' => [
        'heading' => 'Scan from a computer screen',
        'body' => 'Once the phone trusts the certificate, scan this code with the phone camera to open the Field App.',
        'label' => 'QR code for :url',
    ],
    'change' => [
        'heading' => 'Before the address changes',
        'body' => 'When the instance gets another address later (another network address, the tunnel or a domain), data that a phone has not synced yet stays behind with the old address. Sync every phone before the change. Apps added to the home screen keep the old address: add the Field App again from the new address.',
    ],
    'remove' => [
        'heading' => 'Remove the certificate again',
        'warning' => 'A trusted CA can vouch for any website. Remove it from the phone once the demo is over.',
        'ios' => 'iOS: Settings → General → VPN & Device Management → the profile → Remove Profile.',
        'android' => 'Android: Settings → … → Encryption & credentials → User credentials (or Trusted credentials → User) → select the certificate → Remove.',
    ],
];
