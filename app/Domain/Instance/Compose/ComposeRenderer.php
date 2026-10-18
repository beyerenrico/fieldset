<?php

declare(strict_types=1);

namespace App\Domain\Instance\Compose;

use App\Domain\Instance\Compose\Services\AppRoles;
use App\Domain\Instance\Compose\Services\GotenbergService;
use App\Domain\Instance\Compose\Services\MailpitService;
use App\Domain\Instance\Compose\Services\PostgresService;
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
 * Extension points for later units: add a definition to `defaultDefinitions()` (edge with its own network and
 * fixed address U8, tunnel with `restart: "no"` U14, backup with `ServiceEnvironments::backup()` U16), or branch on
 * the mode inside a definition (proxy ports and external network, U9). Lift the mode check in `render()` with
 * the unit that renders that mode.
 */
final readonly class ComposeRenderer
{
    /** The service whose definition `compose run` one-offs use (migrate, app:install, login links, grants). */
    public const string ONE_OFF_SERVICE = AppRoles::APP;

    /** Modes this version renders; domain joins with U8. */
    public const array MODES = ['local', 'proxy'];

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
        return [new AppRoles, new PostgresService, new ValkeyService, new GotenbergService, new MailpitService];
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
            }
        }
        $document['volumes'] = $this->labelled($document['volumes'], $context);
        $document['networks'] = $this->labelled($document['networks'], $context);
        ComposeGuard::assertSafe($document, $environments);

        return new RenderedProject($state->project(), $document, $environments);
    }

    /**
     * Renders and writes everything the stack reads from the instance directory: the runtime directory first
     * (the app fails closed without it, KTD11), then the env files, then the compose file.
     */
    public function write(InstanceDirectory $directory, InstanceState $state, InstanceSecrets $secrets, bool $emulated = false): RenderedProject
    {
        $rendered = $this->render($state, $secrets, $emulated);
        new RuntimeWriter($directory)->write($state);
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
