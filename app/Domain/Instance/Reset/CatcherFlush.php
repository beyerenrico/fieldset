<?php

declare(strict_types=1);

namespace App\Domain\Instance\Reset;

use App\Domain\Instance\Compose\Services\MailpitService;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Docker\ContainerSpec;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\Runtime\CatcherRoute;
use App\Domain\Instance\State\EnvFiles;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceSecrets;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;

/**
 * Empties the mail catcher for `fieldset reset` (plan U23, ADR 0088): the captured mail belongs to the data the reset removes.
 * Mailpit's API deletes every message on `DELETE /api/v1/messages` without IDs; a throwaway curl container on the
 * instance's network sends it with the catcher's login on stdin (never on a command line), under `/_mail/` in domain
 * mode. Without a catcher (SMTP mode with the catcher removed) there is nothing to empty.
 */
final readonly class CatcherFlush
{
    public const string ROLE = 'reset';

    private const float TIMEOUT = 60.0;

    public function __construct(private InstanceDirectory $directory, private DockerGateway $docker) {}

    /** @return bool|null whether the catcher is empty now; null when the instance runs none */
    public function flush(InstanceState $state, ComposeProject $project): ?bool
    {
        if (! InstanceSettings::of($state)->runsCatcher()) {
            return null;
        }
        $login = new EnvFiles($this->directory)->read(MailpitService::NAME)['MP_UI_AUTH'] ?? '';
        if (! str_starts_with($login, InstanceSecrets::CATCHER_USER.':')) {
            return false;
        }
        $webroot = $state->mode() === 'domain' ? CatcherRoute::MAIL_PATH : '/';
        $outcome = $this->docker->runContainer(new ContainerSpec(
            image: $state->digests()['app'],
            command: ['--fail', '--silent', '--show-error', '--max-time', '30', '--request', 'DELETE', '--config', '-', 'http://'.MailpitService::NAME.':'.MailpitService::UI_PORT.$webroot.'api/v1/messages'],
            labels: Labels::forInstance($state->uuid(), self::ROLE),
            network: $project->name.'_default',
            entrypoint: 'curl',
        ), 'user = "'.$login."\"\n", self::TIMEOUT);

        return $outcome->successful();
    }
}
