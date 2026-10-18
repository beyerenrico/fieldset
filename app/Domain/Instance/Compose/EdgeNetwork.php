<?php

declare(strict_types=1);

namespace App\Domain\Instance\Compose;

use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\State\InputGrammar;

/**
 * The network between the edge and the app (plan KTD10, ADR 0075). It has a fixed private subnet, so the edge gets
 * a fixed address that the app trusts as its only proxy (TRUSTED_PROXIES). Docker needs a configured subnet for a
 * fixed container address. Members: edge, app and, in capture mode, Mailpit (the edge serves its UI under /_mail/).
 *
 * The subnet is chosen once, at install, from 10.213.0.0/16 in /28 blocks: the first block derived from the
 * instance UUID that overlaps no existing Docker network. Docker's default address pools (172.17–31/16,
 * 192.168/16) never hand out 10.213/16, so a later network does not collide with it either. It lives in the state
 * (`edge_subnet`) and never changes, so the edge keeps its address across re-renders and `config set`.
 */
final class EdgeNetwork
{
    public const string NAME = 'edge';

    public const int PREFIX = 28;

    /** The /16 the /28 blocks come from: 4096 candidates. */
    private const string POOL = '10.213.0.0';

    private const int ATTEMPTS = 64;

    /** The edge's fixed address: the subnet's second host (the first is Docker's gateway). */
    public static function edgeAddress(string $subnet): string
    {
        self::assertSubnet($subnet);
        [$network] = explode('/', $subnet);

        return long2ip((int) ip2long($network) + 2);
    }

    /**
     * The first candidate subnet for this instance that overlaps none of the given ones (CIDR notation; IPv6 and
     * malformed entries are ignored).
     *
     * @param  list<string>  $taken  subnets of the existing Docker networks
     */
    public static function choose(string $uuid, array $taken): string
    {
        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            $candidate = self::candidate($uuid, $attempt);
            if (! array_any($taken, static fn (string $subnet): bool => self::overlaps($candidate, $subnet))) {
                return $candidate;
            }
        }

        throw new InstanceException(
            'No free private subnet for the edge network in '.self::POOL.'/16.',
            ExitCode::PreflightFailed,
            'edge_subnet_unavailable',
            'Remove unused Docker networks (`docker network prune`), then run the command again.',
        );
    }

    /** Whether two IPv4 CIDR ranges share an address; anything that is not IPv4 CIDR never overlaps. */
    public static function overlaps(string $a, string $b): bool
    {
        $first = self::range($a);
        $second = self::range($b);

        return $first !== null && $second !== null && $first[0] <= $second[1] && $second[0] <= $first[1];
    }

    private static function candidate(string $uuid, int $attempt): string
    {
        $block = (int) hexdec(substr(hash('sha256', $uuid.':'.$attempt), 0, 3)); // 0..4095: a /28 in the /16

        return long2ip((int) ip2long(self::POOL) + $block * 2 ** (32 - self::PREFIX)).'/'.self::PREFIX;
    }

    /** @return array{0: int, 1: int}|null first and last address */
    private static function range(string $cidr): ?array
    {
        if (preg_match('/^([0-9.]+)\/([0-9]{1,2})$/D', trim($cidr), $parts) !== 1 || filter_var($parts[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }
        $prefix = min(32, (int) $parts[2]);
        $size = 2 ** (32 - $prefix);
        $start = intdiv((int) ip2long($parts[1]), $size) * $size;

        return [$start, $start + $size - 1];
    }

    private static function assertSubnet(string $subnet): void
    {
        if (! InputGrammar::isPrivateSubnet($subnet)) {
            throw new InstanceException("The edge subnet [{$subnet}] is not a private IPv4 subnet.", ExitCode::InternalError, 'invalid_edge_subnet');
        }
    }
}
