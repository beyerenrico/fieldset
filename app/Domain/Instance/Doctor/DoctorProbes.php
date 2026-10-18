<?php

declare(strict_types=1);

namespace App\Domain\Instance\Doctor;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Operating-system and network probes behind one seam (like Platform\Health\SystemProbes), so doctor checks can
 * be tested without DNS, a network, a disk or root-owned files. The ops container has no database or cache;
 * nothing here touches them.
 */
class DoctorProbes
{
    /**
     * Addresses of one record type, as the resolver of the ops container (the Docker engine's DNS) answers.
     *
     * @param  int  $type  DNS_A or DNS_AAAA
     * @return list<string>
     */
    public function resolve(string $host, int $type): array
    {
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
}
