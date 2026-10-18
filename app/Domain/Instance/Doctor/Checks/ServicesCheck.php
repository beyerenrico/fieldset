<?php

declare(strict_types=1);

namespace App\Domain\Instance\Doctor\Checks;

use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Docker\ContainerInfo;
use App\Domain\Instance\Doctor\CheckResult;
use App\Domain\Instance\Doctor\DoctorCheck;
use App\Domain\Instance\Doctor\DoctorContext;
use App\Domain\Instance\Lifecycle\ComposeFile;
use App\Domain\Instance\State\InstanceState;

/** `fsa doctor --post` (§7): every service of the compose file runs, and those with a healthcheck are healthy. */
final readonly class ServicesCheck implements DoctorCheck
{
    public function key(): string
    {
        return 'services';
    }

    public function run(DoctorContext $context): CheckResult
    {
        if (! $context->state instanceof InstanceState || ComposeFile::services($context->directory) === []) {
            return CheckResult::fail($this->key(), 'The instance has no services yet.', 'Run `fsa install` to finish the installation.');
        }
        $containers = ComposeFile::serviceContainers($context->docker->composePs(ComposeProject::forInstance($context->state, $context->directory)));
        $problems = [];
        $states = [];
        foreach (ComposeFile::services($context->directory) as $service) {
            $container = array_find($containers, static fn (ContainerInfo $c): bool => $c->service() === $service);
            $states[$service] = $container === null ? 'missing' : $container->state.($container->health === null ? '' : " ({$container->health})");
            if (! $container instanceof ContainerInfo || ! $container->running()) {
                $problems[] = "{$service} is ".($container === null ? 'not created' : $container->state);
            } elseif ($container->health !== null && $container->health !== 'healthy') {
                $problems[] = "{$service} is {$container->health}";
            }
        }
        if ($problems !== []) {
            return CheckResult::fail($this->key(), ucfirst(implode('; ', $problems)).'.', 'Read the logs with `fsa logs <service>`; `fsa install` resumes an unfinished installation.', ['services' => $states]);
        }

        return CheckResult::ok($this->key(), 'All '.count($states).' services run and are healthy.', ['services' => $states]);
    }
}
