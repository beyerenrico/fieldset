<?php

declare(strict_types=1);

namespace App\Domain\Instance\Doctor;

use App\Domain\Instance\Release\BuildFlavor;
use App\Domain\Instance\State\InputGrammar;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Operating-system and network probes behind one seam (like Platform\Health\SystemProbes), so doctor checks can
 * be tested without DNS, a network, a disk or root-owned files. The ops container has no database or cache;
 * nothing here touches them.
 */
class DoctorProbes
{
    /** Harness builds only: fixed DNS answers for the doctor (`host=ip,ip;host2=ip`). */
    public const string TEST_DNS_ENV = 'FSA_TEST_DNS';

    /**
     * Addresses of one record type, as the resolver of the ops container (the Docker engine's DNS) answers.
     *
     * @param  int  $type  DNS_A or DNS_AAAA
     * @return list<string>
     */
    public function resolve(string $host, int $type): array
    {
        $fixed = $this->testDns()[strtolower($host)] ?? null;
        if ($fixed !== null) {
            $family = $type === DNS_AAAA ? FILTER_FLAG_IPV6 : FILTER_FLAG_IPV4;

            return array_values(array_filter($fixed, static fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP, $family) !== false));
        }
        $records = @dns_get_record($host, $type);
        $key = $type === DNS_AAAA ? 'ipv6' : 'ip';

        return is_array($records) ? array_values(array_filter(array_map(static fn (array $record): ?string => is_string($record[$key] ?? null) ? $record[$key] : null, $records))) : [];
    }

    /**
     * An HTTP HEAD request; null when the host cannot be reached at all.
     *
     * @return array{status: int, date: string|null}|null
     */
    public function head(string $url, float $timeoutSeconds = 5.0): ?array
    {
        try {
            $response = Http::timeout((int) ceil($timeoutSeconds))->connectTimeout((int) ceil($timeoutSeconds))->withoutRedirecting()->head($url);
        } catch (Throwable) {
            return null;
        }
        $date = $response->header('Date');

        return ['status' => $response->status(), 'date' => $date === '' ? null : $date];
    }

    /** Free bytes on the file system holding the path; null when unknown. */
    public function diskFree(string $path): ?float
    {
        $free = @disk_free_space($path);

        return $free === false ? null : $free;
    }

    /**
     * Owner and permission bits as this container sees them (KTD2: the in-container owner UID).
     *
     * @return array{uid: int, mode: int}|null
     */
    public function stat(string $path): ?array
    {
        clearstatcache(true, $path);
        $stat = @stat($path);

        return $stat === false ? null : ['uid' => $stat['uid'], 'mode' => $stat['mode'] & 07777];
    }

    public function now(): int
    {
        return time();
    }

    /**
     * `host=ip[,ip…][;host=…]` → host => addresses; anything malformed is dropped entirely (never half a map).
     *
     * @return array<string, list<string>>
     */
    public static function parseTestDns(string $value): array
    {
        $map = [];
        foreach (array_filter(explode(';', $value), static fn (string $entry): bool => trim($entry) !== '') as $entry) {
            [$host, $ips] = array_pad(explode('=', $entry, 2), 2, '');
            $addresses = array_map(trim(...), explode(',', $ips));
            if (! InputGrammar::isHost(strtolower(trim($host))) || ! array_all($addresses, InputGrammar::isIp(...))) {
                return [];
            }
            $map[strtolower(trim($host))] = $addresses;
        }

        return $map;
    }

    /**
     * Fixed DNS answers for the release-harness journeys (ADR 0075): the ops container runs on the engine's default
     * network with its resolver, which knows nothing of the harness DNS (challtestsrv), so J2 passes FSA_TEST_DNS.
     * A production build ignores it (BuildFlavor is read from the image, not the environment).
     *
     * @return array<string, list<string>>
     */
    protected function testDns(): array
    {
        $value = getenv(self::TEST_DNS_ENV);

        return is_string($value) && $value !== '' && BuildFlavor::current() === BuildFlavor::Harness ? self::parseTestDns($value) : [];
    }
}
