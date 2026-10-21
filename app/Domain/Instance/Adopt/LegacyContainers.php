<?php

declare(strict_types=1);

namespace App\Domain\Instance\Adopt;

use App\Domain\Instance\Docker\ContainerInfo;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;

/**
 * The containers of a 1.0 Compose project as `fieldset adopt` accepts them (plan U19, ADR 0087, review S3), looked up by the
 * project label and then inspected one by one (`docker ps` joins labels with commas; inspect returns them exactly, and the
 * volume mounts). Every container must carry Compose's working-directory label naming the checkout and its config-files
 * label naming only the checkout's compose.yaml; one container per service (a `--scale`d service is refused, adopt would
 * leave the extra replica running); no instance label. Used when adopt finds 1.0 and again when it starts 1.0 again,
 * so a container recreated in between is found and checked the same way.
 */
final readonly class LegacyContainers
{
    /**
     * @return array<string, ContainerInfo> service => the inspected container
     *
     * @throws InstanceException exit 7
     */
    public static function find(DockerGateway $docker, string $project, string $checkout): array
    {
        $found = [];
        foreach ($docker->containers([Labels::COMPOSE_PROJECT => $project]) as $listed) {
            if (($listed->labels[LegacyInstall::ONE_OFF] ?? 'False') === 'True') {
                continue;
            }
            $container = $docker->inspectContainer($listed->id);
            if (! $container instanceof ContainerInfo) {
                continue; // removed since it was listed
            }
            $service = self::assertOwn($container, $project, $checkout);
            if (isset($found[$service])) {
                throw self::refused("The service {$service} of the Compose project {$project} runs more than one container (docker compose --scale).", 'legacy_scaled', "Scale it back to one container in the checkout (`docker compose up -d --scale {$service}=1`), then run adopt again.");
            }
            $found[$service] = $container;
        }

        return $found;
    }

    /** @return string the container's service */
    private static function assertOwn(ContainerInfo $container, string $project, string $checkout): string
    {
        $labels = $container->labels;
        if (isset($labels[Labels::INSTANCE])) {
            throw self::refused("The Compose project {$project} belongs to a fieldset instance, not to a 1.0 checkout.", 'already_instance', 'Manage it with that instance\'s fieldset.');
        }
        $dir = $labels[LegacyInstall::WORKING_DIR] ?? null;
        $files = array_values(array_filter(explode(',', $labels[LegacyInstall::CONFIG_FILES] ?? ''), static fn (string $file): bool => $file !== ''));
        $service = $container->service();
        if ($dir === null || $files === [] || $service === null) {
            throw self::refused("The container {$container->name} of the Compose project {$project} lacks Compose's service, working-directory or config-files label.", 'legacy_unlabelled', 'Adopt takes over containers that `docker compose up` in the checkout created; recreate it there (`docker compose up -d`).');
        }
        if (self::real($dir) !== self::real($checkout)) {
            throw self::refused("The Compose project {$project} was started from {$dir}, not from {$checkout}.", 'checkout_mismatch', "Pass the checkout the 1.0 install runs from: fieldset adopt {$dir}");
        }
        if (array_map(self::real(...), $files) !== [self::real($checkout.'/'.LegacyCheckout::COMPOSE_FILE)]) {
            throw self::refused('The 1.0 install was started with these Compose files: '.implode(', ', $files).'. Adopt copies only what compose.yaml of 1.0 defines.', 'compose_file_override', 'Restart 1.0 with compose.yaml alone (COMPOSE_FILE=compose.yaml, `docker compose up -d`), then run adopt again.');
        }

        return $service;
    }

    private static function real(string $path): string
    {
        $real = realpath($path);

        return $real === false ? rtrim($path, '/') : $real;
    }

    private static function refused(string $message, string $code, string $hint): InstanceException
    {
        return new InstanceException($message, ExitCode::Incompatible, $code, $hint);
    }
}
