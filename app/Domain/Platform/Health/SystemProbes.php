<?php

declare(strict_types=1);

namespace App\Domain\Platform\Health;

/** Operating-system probes behind one seam, so health checks can be tested without a network or disk. */
class SystemProbes
{
    public function tcpReachable(string $host, int $port, float $timeoutSeconds = 2.0): bool
    {
        $socket = @fsockopen($host, $port, $errorCode, $errorMessage, $timeoutSeconds);
        if ($socket === false) {
            return false;
        }
        fclose($socket);

        return true;
    }

    /** @return array{free: float, total: float}|null */
    public function diskSpace(string $path): ?array
    {
        $free = @disk_free_space($path);
        $total = @disk_total_space($path);

        return $free === false || $total === false || $total <= 0 ? null : ['free' => $free, 'total' => $total];
    }
}
