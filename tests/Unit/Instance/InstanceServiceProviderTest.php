<?php

declare(strict_types=1);

use App\Domain\Instance\Docker\CliDockerGateway;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Docker\OpsContextRequired;
use App\Domain\Instance\HostFacts;
use App\Domain\Instance\InstanceServiceProvider;
use App\Domain\Instance\State\InstanceDirectory;
use Illuminate\Foundation\Application;
use Tests\Support\Instance\TemporaryInstance;

afterEach(fn () => TemporaryInstance::cleanup());

it('is registered with the application', function (): void {
    expect(app()->getProviders(InstanceServiceProvider::class))->not->toBeEmpty()
        ->and(app(HostFacts::class))->toBeInstanceOf(HostFacts::class);
});

it('registers without database, cache or Docker access', function (): void {
    $app = new Application(base_path());

    new InstanceServiceProvider($app)->register();

    expect($app->bound('db'))->toBeFalse()
        ->and($app->resolved('db'))->toBeFalse()
        ->and($app->resolved('cache'))->toBeFalse()
        ->and($app->resolved(DockerGateway::class))->toBeFalse();
});

it('resolves the Docker gateway only once an instance directory is bound', function (): void {
    expect(fn () => app(DockerGateway::class))->toThrow(OpsContextRequired::class);

    app()->instance(InstanceDirectory::class, TemporaryInstance::directory());

    expect(app(DockerGateway::class))->toBeInstanceOf(CliDockerGateway::class);
});
