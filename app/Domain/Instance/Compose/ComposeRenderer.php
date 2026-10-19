<?php

declare(strict_types=1);

namespace App\Domain\Instance\Compose;

use App\Domain\Instance\Backup\BackupStore;
use App\Domain\Instance\Backup\PortableSettings;
use App\Domain\Instance\Compose\Services\AppRoles;
use App\Domain\Instance\Compose\Services\BackupService;
use App\Domain\Instance\Compose\Services\EdgeService;
use App\Domain\Instance\Compose\Services\GotenbergService;
use App\Domain\Instance\Compose\Services\MailpitService;
use App\Domain\Instance\Compose\Services\PostgresService;
use App\Domain\Instance\Compose\Services\TunnelService;
use App\Domain\Instance\Compose\Services\ValkeyService;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\State\EnvFiles;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceSecrets;
use App\Domain\Instance\State\InstanceState;
use App\Domain\Instance\State\RuntimeWriter;

/**
 * Renders the instance's compose project from its state (KTD6): nobody edits the result. Each ServiceDefinition
 * contributes services; the renderer adds what every service gets (labels, memory limit, env file, log cap,
 * restart policy), declares and labels volumes and networks, and checks the result (ComposeGuard).
 *
 * Extension points for later units: add a definition to `defaultDefinitions()` (the backup service of U16, the quick
 * tunnel with `restart: "no"` of U14), or branch on the mode inside a definition (proxy ports and
 * external network, U9). Lift the mode check in `render()` with the unit that renders that mode. Domain mode (U8)
 * adds EdgeService with its own network and fixed address; definitions may also contribute instance-directory
 * files (the edge's Caddyfile), which `write()` puts next to the compose file.
 */
final readonly class ComposeRenderer
{
    /** The service whose definition `compose run` one-offs use (migrate, app:install, login links, grants). */
    public const string ONE_OFF_SERVICE = AppRoles::APP;

    /** Modes this version renders. */
    public const array MODES = ['local', 'domain', 'proxy'];

    private const array LOGGING = ['driver' => 'json-file', 'options' => ['max-size' => '10m', 'max-file' => '3']];

    /** @var list<ServiceDefinition> */
    private array $definitions;

    /** @param  list<ServiceDefinition>|null  $definitions */
    public function __construct(?array $definitions = null)
    {
        $this->definitions = $definitions ?? self::defaultDefinitions();
    }

    /** @return list<ServiceDefinition> */
    public static function defaultDefinitions(): array
    {
        return [new AppRoles, new PostgresService, new ValkeyService, new GotenbergService, new MailpitService, new EdgeService, new TunnelService, new BackupService];
    }

    /** @param  bool  $emulated  the images run emulated: memory limits doubled (RenderContext) */
    public function render(InstanceState $state, InstanceSecrets $secrets, bool $emulated = false): RenderedProject
    {
        if (! in_array($state->mode(), self::MODES, true)) {
            throw new InstanceException("This version cannot render mode [{$state->mode()}] yet.", ExitCode::InvalidInput, 'unsupported_mode');
        }
        $context = new RenderContext($state, $secrets, $emulated);
        $document = ['name' => $state->project(), 'services' => [], 'networks' => ['default' => []], 'volumes' => []];
        $environments = [];
        $files = [];
        foreach ($this->definitions as $definition) {
            foreach ($definition->specs($context) as $spec) {
                if (array_key_exists($spec->name, $document['services'])) {
                    throw new InstanceException("The service [{$spec->name}] is defined twice.", ExitCode::InternalError, 'duplicate_service');
                }
                EnvFiles::encode($spec->environment);
                $document['services'][$spec->name] = $this->service($spec, $context);
                $environments[$spec->name] = $spec->environment;
                $document['volumes'] = [...$document['volumes'], ...$spec->volumes];
                $document['networks'] = [...$document['networks'], ...$spec->networks];
                $files = [...$files, ...$spec->files];
            }
        }
        $document['volumes'] = $this->labelled($document['volumes'], $context);
        $document['networks'] = $this->labelled($document['networks'], $context);
        ComposeGuard::assertSafe($document, $environments);

        return new RenderedProject($state->project(), $document, $environments, $files);
    }

    /**
     * Renders and writes everything the stack reads from the instance directory: the runtime directory first
     * (the app fails closed without it, KTD11), then the env files, then the compose file. The backup service's
     * bind mounts (`backups/`, `locks/`) must exist with the owner's mode before Compose starts it (Docker would
     * otherwise create them as root), and `backups/.instance.json` gives it the instance's identity and portable
     * settings for every backup's settings.json (0600, no secret).
     */
    public function write(InstanceDirectory $directory, InstanceState $state, InstanceSecrets $secrets, bool $emulated = false): RenderedProject
    {
        $rendered = $this->render($state, $secrets, $emulated);
        new RuntimeWriter($directory)->write($state);
        if (in_array(BackupService::NAME, $rendered->services(), true)) {
            $directory->ensureDirectory(InstanceDirectory::BACKUPS_DIR);
            $directory->ensureDirectory(InstanceDirectory::LOCKS_DIR);
            $directory->write(InstanceDirectory::BACKUPS_DIR.'/'.BackupStore::META_FILE, PortableSettings::document($state));
        }
        $rendered->writeTo($directory);

        return $rendered;
    }

    /** @return array<string, mixed> */
    private function service(ServiceSpec $spec, RenderContext $context): array
    {
        $labels = is_array($spec->definition['labels'] ?? null) ? $spec->definition['labels'] : [];

        return [
            ...$spec->definition,
            'env_file' => ['./'.InstanceDirectory::ENV_DIR.'/'.$spec->name.'.env'],
            'labels' => [...$labels, ...$context->labels($spec->name)],
            'mem_limit' => $context->memoryLimit($spec->name),
            'restart' => $spec->definition['restart'] ?? 'unless-stopped',
            'logging' => self::LOGGING,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $resources
     * @return array<string, array<string, mixed>>
     */
    private function labelled(array $resources, RenderContext $context): array
    {
        return array_map(static function (array $resource) use ($context): array {
            if (($resource['external'] ?? false) === true) {
                return $resource; // someone else's network (proxy mode): Compose neither creates nor labels it
            }
            $labels = is_array($resource['labels'] ?? null) ? $resource['labels'] : [];

            return [...$resource, 'labels' => [...$labels, ...$context->labels()]];
        }, $resources);
    }
}
