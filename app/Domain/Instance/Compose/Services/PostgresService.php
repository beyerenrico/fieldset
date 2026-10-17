<?php

declare(strict_types=1);

namespace App\Domain\Instance\Compose\Services;

use App\Domain\Instance\Compose\ImageRole;
use App\Domain\Instance\Compose\RenderContext;
use App\Domain\Instance\Compose\ServiceDefinition;
use App\Domain\Instance\Compose\ServiceEnvironments;
use App\Domain\Instance\Compose\ServiceSpec;

/**
 * Postgres 16 on Alpine, as in 1.0 (a glibc image would corrupt text indexes of an adopted volume, KTD20), with
 * small-host memory settings and `shm_size` from the budget (E16). The health check asks over TCP, which the
 * entrypoint's temporary init server does not listen on, so dependants never start against it.
 */
final readonly class PostgresService implements ServiceDefinition
{
    public const string NAME = 'postgres';

    private const string VOLUME = 'pg-data';

    public function specs(RenderContext $context): array
    {
        $settings = $context->settings;
        $command = ['postgres'];
        foreach ($context->budget->postgresSettings() as $name => $value) {
            $command[] = '-c';
            $command[] = $name.'='.$value;
        }

        return [new ServiceSpec(self::NAME, [
            'image' => $context->image(ImageRole::Postgres),
            'command' => $command,
            'shm_size' => $context->budget->postgresShmSize(),
            'volumes' => [['type' => 'volume', 'source' => self::VOLUME, 'target' => '/var/lib/postgresql/data']],
            'networks' => ['default'],
            'healthcheck' => [
                'test' => ['CMD', 'pg_isready', '-h', '127.0.0.1', '-U', $settings->dbUsername(), '-d', $settings->dbDatabase()],
                'interval' => '5s', 'timeout' => '5s', 'retries' => 10,
            ],
        ], new ServiceEnvironments($context)->postgres(), [self::VOLUME => []])];
    }
}
