<?php

declare(strict_types=1);

// Vertrauensseite für Telefone im Local-CA-Modus (R19, ADR 0057, ADR 0082). :root ist der Name der CA auf dem Telefon.
return [
    'title' => 'Dieser Instanz auf dem Telefon vertrauen',
    'language' => 'Sprache',
    'intro' => 'Diese Instanz nutzt für HTTPS in Ihrem Netzwerk eine eigene Zertifizierungsstelle (CA). Sobald Ihr Telefon dieser CA vertraut, öffnet es die Field App über HTTPS. Das brauchen die Offline-Nutzung und „Zum Home-Bildschirm“.',
    'demo' => 'Gedacht für Demos und Tests im eigenen Netzwerk. Für den echten Betrieb läuft die Instanz besser unter einer Domain mit öffentlichem Zertifikat.',
    'addresses' => [
        'heading' => 'Adressen',
        'instance' => 'HTTPS-Adresse dieser Instanz',
        'field_app' => 'Field App',
    ],
    'certificate' => [
        'heading' => 'Root-Zertifikat',
        'download' => 'Root-Zertifikat herunterladen',
        'name' => 'Name auf dem Telefon',
        'fingerprint' => 'SHA-256-Fingerabdruck',
        'compare' => 'Vergleichen Sie diesen Fingerabdruck mit dem, den fsa beim Einrichten von HTTPS für Telefone ausgegeben hat (fsa status zeigt ihn ebenfalls), bevor Sie dem Zertifikat vertrauen. Fahren Sie nur fort, wenn beide übereinstimmen: Diese Seite kommt auch über unverschlüsseltes HTTP, jemand im selben Netzwerk könnte das Zertifikat austauschen.',
    ],
    'ios' => [
        'heading' => 'iPhone und iPad (iOS)',
        'steps' => [
            'Öffnen Sie diese Seite in Safari. Andere Browser können unter iOS keine Profile installieren.',
            'Tippen Sie auf „Root-Zertifikat herunterladen“ und erlauben Sie den Download. iOS meldet „Profil geladen“.',
            'Öffnen Sie Einstellungen → Allgemein → VPN und Geräteverwaltung, tippen Sie unter „Geladenes Profil“ auf das Profil, dann auf „Installieren“, und geben Sie Ihren Code ein.',
            'Öffnen Sie Einstellungen → Allgemein → Info → Zertifikatsvertrauenseinstellungen und aktivieren Sie das volle Vertrauen für „:root“.',
            'Kehren Sie zu dieser Seite zurück: Die Prüfung unten zeigt, ob Ihr Telefon der Instanz vertraut.',
        ],
    ],
    'android' => [
        'heading' => 'Android',
        'steps' => [
            'Tippen Sie auf „Root-Zertifikat herunterladen“.',
            'Öffnen Sie Einstellungen → Sicherheit (oder Sicherheit und Datenschutz) → Weitere Sicherheitseinstellungen → Verschlüsselung und Anmeldedaten.',
            'Tippen Sie auf „Zertifikat installieren“ → „CA-Zertifikat“, bestätigen Sie „Trotzdem installieren“ und wählen Sie die heruntergeladene Datei.',
            'Kehren Sie zu dieser Seite zurück: Die Prüfung unten zeigt, ob Ihr Telefon der Instanz vertraut.',
        ],
        'menus' => 'Die Menünamen unterscheiden sich je nach Hersteller. Finden Sie sie nicht, suchen Sie in den Einstellungen nach „CA-Zertifikat“.',
        'tunnel' => 'Viele Android-Versionen und Browser vertrauen selbst installierten CAs bei installierten Web-Apps nicht. Unter Android ist deshalb der Quick Tunnel der empfohlene Weg: Führen Sie fsa tunnel start auf dem Rechner aus, auf dem diese Instanz läuft.',
    ],
    'check' => [
        'heading' => 'Prüfung',
        'checking' => 'Es wird geprüft, ob dieses Telefon der Instanz vertraut …',
        'trusted' => 'Ihr Telefon vertraut dieser Instanz.',
        'untrusted' => 'Ihr Telefon vertraut dieser Instanz noch nicht. Installieren Sie das Zertifikat, aktivieren Sie das volle Vertrauen und prüfen Sie erneut.',
        'again' => 'Erneut prüfen',
        'open' => 'Field App öffnen',
        'noscript' => 'Ohne JavaScript kann diese Seite das Vertrauen nicht prüfen. Sobald das Zertifikat installiert ist und ihm vertraut wird, öffnen Sie die Field App:',
        'elsewhere' => 'Die Prüfung funktioniert nur auf dem Telefon. Öffnen Sie dort :url. Sobald dem Zertifikat vertraut wird, öffnet sich die Field App unter:',
    ],
    'qr' => [
        'heading' => 'Vom Bildschirm eines Computers scannen',
        'body' => 'Sobald das Telefon dem Zertifikat vertraut, scannen Sie diesen Code mit der Kamera des Telefons, um die Field App zu öffnen.',
        'label' => 'QR-Code für :url',
    ],
    'change' => [
        'heading' => 'Bevor sich die Adresse ändert',
        'body' => 'Bekommt die Instanz später eine andere Adresse (eine andere Netzwerkadresse, den Tunnel oder eine Domain), bleiben Daten, die ein Telefon noch nicht synchronisiert hat, unter der alten Adresse zurück. Synchronisieren Sie vor der Änderung jedes Telefon. Apps auf dem Home-Bildschirm behalten die alte Adresse: Fügen Sie die Field App von der neuen Adresse aus erneut hinzu.',
    ],
    'remove' => [
        'heading' => 'Zertifikat wieder entfernen',
        'warning' => 'Eine vertrauenswürdige CA kann für jede beliebige Website bürgen. Entfernen Sie sie vom Telefon, sobald die Demo vorbei ist.',
        'ios' => 'iOS: Einstellungen → Allgemein → VPN und Geräteverwaltung → das Profil → „Profil entfernen“.',
        'android' => 'Android: Einstellungen → … → Verschlüsselung und Anmeldedaten → Nutzeranmeldedaten (oder Vertrauenswürdige Anmeldedaten → Nutzer) → das Zertifikat auswählen → Entfernen.',
    ],
];
