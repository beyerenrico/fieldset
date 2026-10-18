<?php

declare(strict_types=1);

namespace App\Domain\Instance\Config\Appliers;

use App\Domain\Instance\Config\ConfigRequest;
use App\Domain\Instance\Install\AcmeCaRoot;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceState;

/**
 * The ACME options of `config set domain` and `config set acme-ca` (ADR 0075, ADR 0077) as state settings: the
 * contact e-mail, the directory (null = Let's Encrypt production) and the SHA-256 of a custom CA root for the
 * directory's HTTPS. The PEM itself is returned only when it is not already the file next to the Caddyfile, so
 * repeating the same options is no change. Switching to a Let's Encrypt directory drops a custom root.
 */
final readonly class AcmeSettings
{
    /** @param  array<string, string|null>  $settings */
    private function __construct(public array $settings, public ?string $caRoot) {}

    public static function from(ConfigRequest $request, InstanceDirectory $directory): self
    {
        $settings = [];
        if ($request->acmeEmail !== null) {
            $settings['acme_email'] = ConfigValues::email($request->acmeEmail, 'acme-email');
        }
        if ($request->acmeCa !== null) {
            $settings['acme_ca'] = ConfigValues::acmeCa($request->acmeCa);
        }
        $digest = $request->caRootDigest();
        if ($digest !== null) {
            $settings['acme_ca_root'] = $digest;
        } elseif (array_key_exists('acme_ca', $settings) && ($settings['acme_ca'] === null || str_contains((string) $settings['acme_ca'], 'letsencrypt.org'))) {
            $settings['acme_ca_root'] = null;
        }
        $caRoot = $digest !== null && ! AcmeCaRoot::present($directory, $digest) ? $request->acmeCaRoot : null;

        return new self($settings, $caRoot);
    }

    /** The state with these settings over its own (unchanged when they equal it). */
    public function applyTo(InstanceState $state): InstanceState
    {
        return $this->settings === [] ? $state : $state->withSettings($this->settings);
    }
}
