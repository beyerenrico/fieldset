<?php

declare(strict_types=1);

namespace App\Domain\Instance\Doctor\Checks;

use App\Domain\Instance\Docker\WslBackend;
use App\Domain\Instance\Doctor\CheckResult;
use App\Domain\Instance\Doctor\DoctorCheck;
use App\Domain\Instance\Doctor\DoctorContext;
use App\Domain\Instance\Windows\WslIdleTimeouts;

/**
 * Whether the instance keeps running with its WSL 2 distro (2.1.1 U7, R31, R8). Skipped outside WSL 2.
 *
 * - Docker Engine in the distro: WSL stops a distro without a terminal after `instanceIdleTimeout` (the instance with
 *   it), and Docker Engine starts with the distro only through systemd. A warning names the exact lines for
 *   %UserProfile%\.wslconfig (`[general]`, `instanceIdleTimeout=-1`) and /etc/wsl.conf (`[boot]`, `systemd=true`), then
 *   `wsl --shutdown`. When `.wslconfig` could not be read (interop off) or the launcher did not report systemd, and
 *   nothing else warns, the result is `unknown` (`wsl_lifecycle_unknown`) with the same lines as manual steps.
 * - Docker Desktop: no Engine warnings. The result passes as information: Desktop starts only after the Windows
 *   sign-in, and whether WSL stopping an idle distro affects the instance is unknown until verified on Windows (U1).
 *   A pass, not `unknown`, because no setting of this host changes it and a permanent warning would hide real ones.
 * - Neither engine known: `unknown`.
 *
 * `details`: `backend`, `instance_idle_timeout` and `vm_idle_timeout` (ms; null when not set or not read), `wslconfig`
 * (`read` or `unknown`), `systemd` (bool or null); `idle_distro: unknown` on Docker Desktop.
 */
final readonly class WslLifecycleCheck implements DoctorCheck
{
    public const string UNKNOWN = 'wsl_lifecycle_unknown';

    private const string IDLE = "Keep the distro running without a terminal: add to %UserProfile%\\.wslconfig on Windows:\n    [general]\n    instanceIdleTimeout=-1";

    private const string SYSTEMD = "Start Docker with the distro: add to /etc/wsl.conf in the distro:\n    [boot]\n    systemd=true";

    private const string RESTART = 'Then run `wsl --shutdown` in PowerShell and open the distro again.';

    public function key(): string
    {
        return 'wsl_lifecycle';
    }

    public function run(DoctorContext $context): CheckResult
    {
        $facts = $context->facts;
        if (! $facts->onWsl2()) {
            return CheckResult::skipped($this->key(), 'Not a WSL 2 host.');
        }
        $idle = $facts->windows?->idleTimeouts;
        $details = ['backend' => $facts->wslBackend?->value, 'instance_idle_timeout' => $idle?->instance, 'vm_idle_timeout' => $idle?->vm,
            'wslconfig' => $idle instanceof WslIdleTimeouts ? 'read' : 'unknown', 'systemd' => $facts->wslSystemd];

        return match ($facts->wslBackend) {
            WslBackend::Engine => $this->engine($idle, $facts->wslSystemd, $details),
            WslBackend::DockerDesktop => CheckResult::ok($this->key(), 'Docker Desktop runs the instance: it starts only after you sign in to Windows. Whether WSL stopping an idle distro affects the instance is unknown until verified.', [...$details, 'idle_distro' => 'unknown']),
            null => CheckResult::unknown($this->key(), 'Which Docker engine runs in this WSL 2 distro is unknown.', 'For Docker Engine in the distro: '.$this->hint([self::IDLE, self::SYSTEMD]), $details, self::UNKNOWN),
        };
    }

    /** @param  array<string, mixed>  $details */
    private function engine(?WslIdleTimeouts $idle, ?bool $systemd, array $details): CheckResult
    {
        $stops = $idle instanceof WslIdleTimeouts && ! $idle->keepsDistro();
        $found = array_filter([ // [problem, fix]
            $stops ? ['The distro stops when its last terminal closes, Docker Engine and the instance with it (WSL\'s instanceIdleTimeout is '.($idle->instance ?? 'not set').')', self::IDLE] : null,
            $systemd === false ? ['Docker Engine does not start with the distro (systemd is not PID 1)', self::SYSTEMD] : null,
        ]);
        if ($found !== []) {
            $unread = $idle instanceof WslIdleTimeouts ? '' : ' WSL\'s idle timeout could not be read from .wslconfig.';

            return CheckResult::warn($this->key(), implode('; ', array_column($found, 0)).'.'.$unread, $this->hint(array_column($found, 1)), $details);
        }
        if (! $idle instanceof WslIdleTimeouts || $systemd === null) {
            $what = $idle instanceof WslIdleTimeouts ? 'whether systemd runs as PID 1 was not reported' : 'WSL\'s idle timeout could not be read from .wslconfig (interop off)';

            return CheckResult::unknown($this->key(), "Whether Docker Engine keeps running with the distro is unknown: {$what}.", 'Check by hand: '.$this->hint([self::IDLE, self::SYSTEMD]), $details, self::UNKNOWN);
        }

        return CheckResult::ok($this->key(), 'Docker Engine starts with the distro (systemd) and keeps running without a terminal (instanceIdleTimeout=-1).', $details);
    }

    /** @param  list<string>  $fixes */
    private function hint(array $fixes): string
    {
        return implode("\n", [...$fixes, self::RESTART]);
    }
}
