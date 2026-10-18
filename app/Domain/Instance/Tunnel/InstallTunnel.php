<?php

declare(strict_types=1);

namespace App\Domain\Instance\Tunnel;

use App\Domain\Instance\Console\FsaContext;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\CommandResult;

/**
 * `fsa install --phone-https tunnel` (§7, R20, ADR 0082): the install finishes in local mode, then the quick tunnel
 * starts as `fsa tunnel start` would, in its own operation (the install's lock is released by then). The install's
 * command line is the confirmation `--yes` would give: the organization it just created is the only one besides the
 * demo. The envelope stays the install's, with the tunnel under `data.tunnel`, the tunnel's URL as `data.url`, and
 * the tunnel's warnings and secrets (rotated demo passwords) added; a tunnel that fails leaves the installed instance
 * running in local mode and makes the command fail with the tunnel's exit code.
 */
final class InstallTunnel
{
    public static function start(CommandResult $install, TunnelOperation $tunnel, FsaContext $context, bool $toFile): CommandResult
    {
        try {
            $started = $tunnel->start($context, false, true, null, $toFile);
        } catch (InstanceException $e) {
            $started = CommandResult::fromException(TunnelResults::COMMAND, $e);
        }
        $result = $install->withData(['tunnel' => $started->data['tunnel'] ?? null, ...(is_string($started->data['url'] ?? null) ? ['url' => $started->data['url']] : [])]);
        foreach ($started->warnings as $warning) {
            $result = $result->withWarning($warning->code, $warning->message, $warning->hint);
        }
        foreach ($started->secrets as $secret) {
            $result = $result->withSecret($secret);
        }
        if ($started->exitCode === $install->exitCode) {
            return $result->withData(['demo' => $started->data['demo'] ?? $install->data['demo'] ?? null]);
        }
        foreach ($started->errors as $error) {
            $result = $result->withError($error->code, 'The instance is installed, but the quick tunnel did not start: '.$error->message, $error->hint ?? 'Run `fsa tunnel start`.', (bool) $error->retryable);
        }

        return $result->withExitCode($started->exitCode);
    }
}
