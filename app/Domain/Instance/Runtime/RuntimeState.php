<?php

declare(strict_types=1);

namespace App\Domain\Instance\Runtime;

use Carbon\CarbonImmutable;

/**
 * The validated content of runtime.json (KTD11): the instance URL, the local URL (local mode only), extra hosts,
 * the tunnel, the operation gate, whether webhooks are paused, the instance facts and the update check. Built by RuntimeDocument::parse(); a fallback state carries only APP_URL and
 * a raised gate.
 */
final readonly class RuntimeState
{
    /** Spellings of the loopback URL that browsers use interchangeably (all are trusted hosts anyway). */
    private const array LOOPBACK_NAMES = ['localhost', '127.0.0.1', '::1'];

    /**
     * @param  list<string>  $extraHosts  normalized host names or IP addresses (HostName)
     * @param  bool  $webhooksPaused  after `fieldset install --from-backup` until the operator confirms the move (ADR 0085):
     *                                webhook steps wait (RunWorkflowStep)
     * @param  InstanceFacts|null  $instance  release, mode, digests and catcher for the /platform instance page (U20)
     * @param  string|null  $updateManifestUrl  the release manifest the daily update check reads; null = the check is off (E15)
     */
    public function __construct(
        public string $instanceUrl,
        public ?string $localUrl,
        public array $extraHosts,
        public TunnelState $tunnel,
        public GateState $gate,
        public bool $webhooksPaused = false,
        public ?InstanceFacts $instance = null,
        public ?string $updateManifestUrl = null,
    ) {}

    /** No usable file and no last good value in this process: APP_URL, and the gate counts as raised (fail closed). */
    public static function fallback(string $appUrl): self
    {
        return new self(rtrim($appUrl, '/'), null, [], TunnelState::none(), GateState::failClosed());
    }

    /** The address jobs, mails and unknown hosts use: the active tunnel while there is one, else the instance URL. */
    public function publicUrl(CarbonImmutable $now): string
    {
        return $this->tunnel->url($now) ?? $this->instanceUrl;
    }

    /**
     * Hosts whose requests keep their own origin as the URL root: local URL (with every loopback spelling when it
     * is a loopback URL, so a sign-in on 127.0.0.1 is not redirected to localhost's cookie jar), instance URL,
     * extra hosts and the active tunnel host.
     *
     * @return list<string>
     */
    public function allowedHosts(CarbonImmutable $now): array
    {
        $hosts = [HostName::fromUrl($this->instanceUrl), ...$this->extraHosts];
        if ($this->localUrl !== null) {
            $local = HostName::fromUrl($this->localUrl);
            array_push($hosts, $local, ...(HostName::isLoopback($local) ? self::LOOPBACK_NAMES : []));
        }
        if ($this->tunnel->isActive($now) && $this->tunnel->hostname !== null) {
            $hosts[] = $this->tunnel->hostname;
        }

        return array_values(array_unique(array_filter($hosts, static fn (string $host): bool => $host !== '')));
    }

    /**
     * Hosts the app answers at all (TrustedHosts): the allowed hosts plus an expired tunnel host, which the runtime
     * middleware answers with 503 instead of the 400 of an unknown host.
     *
     * @return list<string>
     */
    public function knownHosts(CarbonImmutable $now): array
    {
        $hosts = $this->allowedHosts($now);
        if ($this->tunnel->hostname !== null) {
            $hosts[] = $this->tunnel->hostname;
        }

        return array_values(array_unique($hosts));
    }

    public function isAllowedHost(string $host, CarbonImmutable $now): bool
    {
        return in_array(HostName::normalize($host), $this->allowedHosts($now), true);
    }

    public function isTunnelHost(string $host): bool
    {
        return $this->tunnel->hostname !== null && HostName::normalize($host) === $this->tunnel->hostname;
    }

    public function isLocalHost(string $host): bool
    {
        if ($this->localUrl === null) {
            return false;
        }
        $local = HostName::fromUrl($this->localUrl);
        $host = HostName::normalize($host);

        return $host === $local || (HostName::isLoopback($local) && in_array($host, self::LOOPBACK_NAMES, true));
    }

    public function isInstanceHost(string $host): bool
    {
        return HostName::normalize($host) === HostName::fromUrl($this->instanceUrl);
    }
}
