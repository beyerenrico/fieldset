<?php

declare(strict_types=1);

namespace App\Domain\Instance\Lifecycle;

use App\Domain\Instance\Compose\ComposeRenderer;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\Install\InstallRequest;

/**
 * App-side artisan commands as socket-less one-offs (KTD25, ADR 0068): `compose run --rm --no-deps app …`. The
 * one-off gets the app service's env file and runtime mount but never the Docker socket, so the process holding
 * the socket never opens a database connection. Values travel as arguments without a shell; an email is placed
 * after `--` so it can never be read as an option.
 */
final readonly class AppCommands
{
    public const float MIGRATE_TIMEOUT = 900.0;

    public const float TIMEOUT = 300.0;

    public function __construct(private DockerGateway $docker) {}

    public function migrate(ComposeProject $project): ProcessOutcome
    {
        return $this->docker->composeRun($project, ComposeRenderer::ONE_OFF_SERVICE, ['php', 'artisan', 'migrate', '--force', '--no-interaction'], timeout: self::MIGRATE_TIMEOUT);
    }

    /** Creates the organization and owner, grants the operator role and issues the 60-minute owner link. */
    public function install(ComposeProject $project, InstallRequest $request, int $actorUid): OneOffResult
    {
        return $this->json($project, [
            'php', 'artisan', 'app:install', '--organization='.$request->organization(), '--name='.$request->ownerName(),
            '--email='.$request->ownerEmail(), '--locale='.$request->locale(), '--owner-link', '--actor-uid='.$actorUid, '--json',
        ]);
    }

    /** A 15-minute single-use sign-in link for an existing admin (R28). */
    public function loginLink(ComposeProject $project, string $email, int $actorUid): OneOffResult
    {
        return $this->json($project, ['php', 'artisan', 'identity:login-link', '--actor-uid='.$actorUid, '--json', '--', $email]);
    }

    /** Sign-in links without their tokens (`fsa status`). */
    public function loginLinks(ComposeProject $project, bool $unusedOnly = true): OneOffResult
    {
        return $this->json($project, ['php', 'artisan', 'identity:login-links', ...($unusedOnly ? ['--unused'] : []), '--json'], 120.0);
    }

    /** What follows the instance URL: organizations with SSO and links already sent (`fsa config set`, ADR 0077). */
    public function urlImpact(ComposeProject $project): OneOffResult
    {
        return $this->json($project, ['php', 'artisan', 'instance:url-impact', '--json'], 120.0);
    }

    /**
     * Audits a settings change as `instance.config_changed` by the instance owner at the command line (KTD26): key,
     * old and new value (never secret: every settable key is a plain value) and, when it moved, the instance URL.
     *
     * @param  array{key: string, from: string|null, to: string|null, url: array{from: string, to: string}}  $change
     */
    public function auditConfigChange(ComposeProject $project, array $change, int $actorUid): OneOffResult
    {
        $url = $change['url']['from'] === $change['url']['to'] ? [] : ['--old-url='.$change['url']['from'], '--new-url='.$change['url']['to']];

        return $this->json($project, [
            'php', 'artisan', 'instance:config-changed', '--key='.$change['key'], '--old='.($change['from'] ?? ''), '--new='.($change['to'] ?? ''),
            ...$url, '--actor-uid='.$actorUid, '--json',
        ], 120.0);
    }

    /** @param  list<string>  $command */
    private function json(ComposeProject $project, array $command, float $timeout = self::TIMEOUT): OneOffResult
    {
        return OneOffResult::fromOutcome($this->docker->composeRun($project, ComposeRenderer::ONE_OFF_SERVICE, $command, timeout: $timeout));
    }
}
