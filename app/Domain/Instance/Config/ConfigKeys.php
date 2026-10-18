<?php

declare(strict_types=1);

namespace App\Domain\Instance\Config;

use App\Domain\Instance\Config\Appliers\AcmeCaApplier;
use App\Domain\Instance\Config\Appliers\AcmeEmailApplier;
use App\Domain\Instance\Config\Appliers\DomainApplier;
use App\Domain\Instance\Config\Appliers\ModeApplier;
use App\Domain\Instance\Config\Appliers\PortApplier;
use App\Domain\Instance\Config\Appliers\TrustedProxyApplier;
use App\Domain\Instance\Config\Appliers\UrlApplier;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;
use Illuminate\Contracts\Container\Container;

/**
 * The keys of `fsa config set` (§7, R32, ADR 0077) and their appliers, one per key. Keys of later pre-releases are
 * known and refused with exit 2 and the release that brings them, so an agent following a newer guide gets a clear
 * answer instead of "unknown setting". `availability()` says per key where it applies and what to use instead
 * (`config show`, ADR 0078).
 */
final readonly class ConfigKeys
{
    /** Key => applier. */
    public const array APPLIERS = [
        'domain' => DomainApplier::class,
        'url' => UrlApplier::class,
        'port' => PortApplier::class,
        'trusted-proxy' => TrustedProxyApplier::class,
        'mode' => ModeApplier::class,
        'acme-email' => AcmeEmailApplier::class,
        'acme-ca' => AcmeCaApplier::class,
    ];

    /** Key => the modes it can be set in (`port` in proxy mode only in the `port` setup; `mode` takes only `local`). */
    public const array MODES = [
        'domain' => ['local', 'proxy', 'domain'],
        'url' => ['proxy'],
        'port' => ['local', 'proxy'],
        'trusted-proxy' => ['proxy'],
        'mode' => ['domain', 'proxy'],
        'acme-email' => ['domain'],
        'acme-ca' => ['domain'],
    ];

    /** Key => mode => the key that does the job there instead. */
    private const array INSTEAD = [
        'url' => ['local' => 'port', 'domain' => 'domain'],
        'port' => ['domain' => 'mode'],
        'mode' => ['local' => 'domain'],
        'acme-email' => ['local' => 'domain', 'proxy' => 'domain'],
        'acme-ca' => ['local' => 'domain', 'proxy' => 'domain'],
    ];

    /** Keys of R32 that later pre-releases bring => that release. */
    public const array LATER = [
        'mail' => '2.0.0-beta.1',
        'operator-email' => '2.0.0-beta.1',
        'backup-schedule' => '2.0.0-rc.1',
        'backup-retention' => '2.0.0-rc.1',
        'backup-encryption' => '2.0.0-rc.1',
        'update-check' => '2.0.0',
    ];

    /** Keys whose value changes the instance URL in some mode: they need --yes and list what follows the URL. */
    public const array URL_KEYS = ['domain', 'url', 'port', 'mode'];

    public function __construct(private Container $container) {}

    /** @throws InvalidInput exit 2 for an unknown key or one that arrives later */
    public function applier(string $key): SettingApplier
    {
        self::check($key);

        /** @var SettingApplier */
        return $this->container->make(self::APPLIERS[$key]);
    }

    /**
     * A key this version sets; else exit 2, `setting_not_available` (with the release) or `unknown_setting`. Checked
     * before the value, so `fsa config set mail` says when mail arrives instead of asking for a value.
     *
     * @throws InvalidInput
     */
    public static function check(string $key): void
    {
        if (array_key_exists($key, self::APPLIERS)) {
            return;
        }
        $release = self::LATER[$key] ?? null;
        if ($release !== null) {
            $hint = $key === 'mail' ? ' Until then mail stays in capture mode: the catcher holds every instance mail.' : '';

            throw new InvalidInput("`fsa config set {$key}` is not available in this version; it arrives with {$release}.{$hint}", 'setting_not_available', 'Run `fsa config show` for the settings this version can change.');
        }

        throw new InvalidInput(
            'Unknown setting'.(preg_match('/^[a-z0-9-]{1,40}$/D', $key) === 1 ? " [{$key}]" : '').'.',
            'unknown_setting',
            'Settings: '.implode(', ', array_keys(self::APPLIERS)).'. Run `fsa config show` for their values.',
        );
    }

    /**
     * Where a key applies and whether it can be set on this instance now; else the key that does the job here
     * (`url` in local mode follows the port, the ACME keys come with `domain`), or null when nothing does.
     *
     * @return array{modes: list<string>, settable_now: bool, use_instead: string|null}
     */
    public static function availability(string $key, InstanceState $state): array
    {
        $modes = self::MODES[$key] ?? [];
        $settable = in_array($state->mode(), $modes, true) && ($key !== 'port' || InstanceSettings::of($state)->publishesAppPort());

        return ['modes' => $modes, 'settable_now' => $settable, 'use_instead' => $settable ? null : (self::INSTEAD[$key][$state->mode()] ?? null)];
    }
}
