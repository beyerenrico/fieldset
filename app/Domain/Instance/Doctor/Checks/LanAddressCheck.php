<?php

declare(strict_types=1);

namespace App\Domain\Instance\Doctor\Checks;

use App\Domain\Instance\Doctor\CheckResult;
use App\Domain\Instance\Doctor\DoctorCheck;
use App\Domain\Instance\Doctor\DoctorContext;
use App\Domain\Instance\LocalCa\LanAddress;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;

/**
 * Local-CA mode on an installed instance (R19, ADR 0082): whether the LAN address the certificate was issued for is
 * still one of this host's addresses (FIELDSET_LAN_ADDRESSES). A laptop that moved to another network has a new address,
 * phones no longer reach the old one, and the edge may not even bind it: the warning offers to re-issue the
 * certificate for the best current candidate. Skipped outside local-CA mode, and when the launcher reported no
 * addresses at all. On WSL 2 the address is Windows', and `windows_network` compares it together with what Windows lets
 * through (2.1.1 U14), so this check is skipped there and the warning comes once.
 */
final readonly class LanAddressCheck implements DoctorCheck
{
    public const string WARNING = 'lan_address_changed';

    public function key(): string
    {
        return 'lan_address';
    }

    public function run(DoctorContext $context): CheckResult
    {
        $settings = $context->state instanceof InstanceState ? InstanceSettings::of($context->state) : null;
        $address = $settings?->localCa() === true ? $settings->lanAddress() : null;
        if ($address === null) {
            return CheckResult::skipped($this->key(), 'Phone HTTPS with the local CA is off.');
        }
        if ($context->facts->wslVersion === 2) {
            return CheckResult::skipped($this->key(), 'On WSL 2 the windows_network check compares the address with Windows\' LAN addresses.', ['address' => $address]);
        }
        $current = LanAddress::isCurrent($address, $context->facts);
        if ($current === null) {
            return CheckResult::skipped($this->key(), 'The host reported no LAN addresses to compare with.', ['address' => $address]);
        }
        if ($current) {
            return CheckResult::ok($this->key(), "The local CA's certificate is for {$address}, one of this host's addresses.", ['address' => $address]);
        }

        return CheckResult::warn($this->key(), "The local CA's certificate is for {$address}, which is no longer an address of this host.", self::hint(LanAddress::best($context->facts)), ['address' => $address, 'candidates' => $context->facts->lanAddresses], self::WARNING);
    }

    /** The offer to re-issue: `config set lan-address` with the best candidate the host reports. */
    public static function hint(?string $candidate): string
    {
        return $candidate === null
            ? 'Re-issue the certificate for the current address: `fieldset config set lan-address <address> --yes`.'
            : "Re-issue the certificate for {$candidate}: `fieldset config set lan-address {$candidate} --yes` (phones then open https://{$candidate}).";
    }
}
