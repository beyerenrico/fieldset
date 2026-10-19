<?php

declare(strict_types=1);

namespace App\Domain\Instance\LocalCa;

use App\Domain\Instance\HostFacts;
use App\Domain\Instance\Runtime\PhoneTrustRoute;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;
use App\Domain\Instance\State\RuntimeWriter;

/**
 * What install, `config set` and `fsa status` report about local-CA mode (R19, ADR 0082): the LAN address, the HTTPS
 * URL, the plain-HTTP trust page for the phone, and the root's SHA-256 fingerprint, the same the trust page shows, so a
 * person can compare both before trusting the CA. Read-only.
 */
final class LocalCaInfo
{
    /**
     * @return array{mode: string, lan_address: string, url: string, trust_url: string, root_sha256: string|null, lan_address_current: bool|null}|null
     */
    public static function data(InstanceState $state, InstanceDirectory $directory, ?HostFacts $facts = null): ?array
    {
        $settings = InstanceSettings::of($state);
        $address = $settings->lanAddress();
        if (! $settings->localCa() || $address === null) {
            return null;
        }
        $root = new RuntimeWriter($directory)->localCaRoot();
        $root = $root === null ? null : LocalCaStore::publicRoot($root);

        return [
            'mode' => 'local-ca',
            'lan_address' => $address,
            'url' => 'https://'.$address,
            'trust_url' => self::trustUrl($address),
            'root_sha256' => $root === null ? null : LocalCaStore::fingerprint($root),
            'lan_address_current' => $facts instanceof HostFacts ? LanAddress::isCurrent($address, $facts) : null,
        ];
    }

    public static function trustUrl(string $address): string
    {
        return 'http://'.$address.PhoneTrustRoute::PATH;
    }

    /** @return list<array{0: string, 1: string, 2: string|null}> what a person must know before using it */
    public static function warnings(string $address): array
    {
        return [
            ['local_ca_trust', "Phones that trust this instance's CA accept any certificate it signs: compare the fingerprint on ".self::trustUrl($address).' with the one printed here before trusting it, and remove the CA from the phone after the demo.', null],
            ['lan_exposed', "The instance answers on {$address} (ports 80 and 443) for every device in this network.", 'Turn it off with `fsa config set phone-https off --yes`.'],
            ['url_changed_phone_data', 'Data a phone has not synced yet stays behind when the instance URL changes later (another LAN address, a tunnel or a domain), and home-screen apps keep the old address.', 'Let every phone sync before you change the URL.'],
        ];
    }
}
