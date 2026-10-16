<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Webhooks;

use App\Domain\Workflows\Engine\StepFailedException;
use App\Support\Network\HostResolver;
use App\Support\Network\PublicAddress;

/**
 * SSRF guard of the webhook step (§8.3, §11): http(s) URLs without credentials on a web port (80, 443, 8080,
 * 8443) whose host resolves only to public addresses (private, loopback, link-local, CGNAT, multicast, reserved, IPv6 and IPv4-mapped
 * ranges are refused). The request then connects to the validated address (curl CURLOPT_RESOLVE), so a
 * DNS answer that changes between check and connect cannot redirect it; Host header and TLS SNI keep the
 * host name. `workflows.webhooks.allow_private_hosts` lifts the address and port checks for development.
 */
final readonly class WebhookTarget
{
    private function __construct(public string $url, public string $host, public int $port, public string $address, public bool $literal) {}

    public static function resolve(string $url, HostResolver $resolver): self
    {
        [$host, $port] = self::parse($url);
        self::checkPort($port);
        $literal = filter_var($host, FILTER_VALIDATE_IP) !== false;
        $addresses = $literal ? [$host] : $resolver->resolve($host);
        if (! (bool) config('workflows.webhooks.allow_private_hosts') && ! PublicAddress::allPublic($addresses)) {
            throw new StepFailedException(__('workflows.errors.webhook_blocked', ['host' => $host]));
        }
        if ($addresses === []) {
            throw new StepFailedException(__('workflows.errors.webhook_unresolved', ['host' => $host]));
        }
        $ipv4 = array_values(array_filter($addresses, static fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false));

        return new self($url, $host, $port, $ipv4[0] ?? $addresses[0], $literal);
    }

    /** Syntax check for the endpoint form (no DNS lookup): scheme, host, no credentials, no private IP literal. */
    public static function syntaxProblem(string $url): ?string
    {
        try {
            [$host, $port] = self::parse($url);
            self::checkPort($port);
        } catch (StepFailedException $e) {
            return $e->getMessage();
        }
        $literal = filter_var($host, FILTER_VALIDATE_IP) !== false;
        if ($literal && ! (bool) config('workflows.webhooks.allow_private_hosts') && ! PublicAddress::isPublic($host)) {
            return (string) __('workflows.errors.webhook_blocked', ['host' => $host]);
        }

        return null;
    }

    /**
     * Guzzle options: pinned address, no redirects, no proxy.
     *
     * @return array<string, mixed>
     */
    public function options(): array
    {
        $options = ['allow_redirects' => false, 'proxy' => ''];
        if (! $this->literal) {
            $address = str_contains($this->address, ':') ? '['.$this->address.']' : $this->address;
            $options['curl'] = [CURLOPT_RESOLVE => ["{$this->host}:{$this->port}:{$address}"]];
        }

        return $options;
    }

    /** Web ports only (`workflows.webhooks.allowed_ports`), unless private hosts are allowed for development. */
    private static function checkPort(int $port): void
    {
        $allowed = array_map(intval(...), (array) config('workflows.webhooks.allowed_ports', [80, 443, 8080, 8443]));
        if (! (bool) config('workflows.webhooks.allow_private_hosts') && ! in_array($port, $allowed, true)) {
            throw new StepFailedException(__('workflows.errors.webhook_port', ['port' => $port]));
        }
    }

    /** @return array{0: string, 1: int} */
    private static function parse(string $url): array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        if (! is_array($parts) || ! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new StepFailedException(__('workflows.errors.webhook_url'));
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new StepFailedException(__('workflows.errors.webhook_credentials'));
        }

        return [$host, (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80))];
    }
}
