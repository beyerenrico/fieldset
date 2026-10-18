<?php

declare(strict_types=1);

namespace App\Domain\Instance\Compose;

use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InvalidState;

/**
 * What the edge's Caddyfile is rendered from in local-CA mode (R19, plan KTD10, ADR 0082): the LAN address the
 * certificate is issued for (an IPv4 address, validated again by the renderer). Caddy's internal CA signs it; clients
 * connecting by IP send no SNI, so `default_sni` names the address.
 */
final readonly class LocalCaSite
{
    /** The CA's name: Caddy puts it into the root's and the intermediate's common names. */
    public const string CA_NAME = 'form-schema-app local CA';

    public function __construct(public string $address) {}

    public static function fromSettings(InstanceSettings $settings): self
    {
        return new self($settings->lanAddress() ?? throw new InvalidState('Local-CA mode needs the setting [lan_address].'));
    }

    public function httpsUrl(): string
    {
        return 'https://'.$this->address;
    }
}
