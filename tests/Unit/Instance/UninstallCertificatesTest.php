<?php

declare(strict_types=1);

use App\Domain\Instance\Compose\Services\EdgeService;
use App\Domain\Instance\Docker\DockerResource;
use App\Domain\Instance\Docker\Labels;
use Tests\Support\Instance\ConfigFixture;
use Tests\Support\Instance\TemporaryInstance;

/*
 * M10 gate (ADR 0078): the Let's Encrypt duplicate-certificate warning of `fsa uninstall` follows the certificate
 * volume, not the current mode. After `config set mode local` the edge is gone but its volume (and certificates)
 * stay until uninstall; an instance that never ran the edge has none and gets no warning.
 */

afterEach(fn () => TemporaryInstance::cleanup());

it('warns about the certificates after a return to local mode, because the edge volume still holds them', function (): void {
    $config = new ConfigFixture;
    $config->set('domain', ConfigFixture::DOMAIN, ['--yes' => true]);
    $config->set('mode', 'local', ['--yes' => true]);
    $state = $config->state();
    $config->instance->docker->withResource(new DockerResource(DockerResource::VOLUME, EdgeService::volumeName($state->project()), Labels::forInstance($state->uuid())));

    [$asked, $prompt] = $config->instance->run('fsa:uninstall');
    [$code, $json] = $config->instance->run('fsa:uninstall', ['--yes' => true]);

    expect($state->setting('domain'))->toBeNull()
        ->and($asked)->toBe(6)
        ->and($prompt['errors'][0]['message'])->toContain('The certificates this instance obtained in domain mode were removed')
        ->and($code)->toBe(0, json_encode($json))
        ->and(collect($json['warnings'])->pluck('code')->all())->toContain('certificates_removed');
});

it('does not warn about certificates for an instance that never held any', function (): void {
    $config = new ConfigFixture;
    $state = $config->state();
    $config->instance->docker->withResource(new DockerResource(DockerResource::VOLUME, $state->project().'_pg-data', Labels::forInstance($state->uuid())));

    [, $prompt] = $config->instance->run('fsa:uninstall');
    [$code, $json] = $config->instance->run('fsa:uninstall', ['--yes' => true]);

    expect($prompt['errors'][0]['message'])->not->toContain('certificates')
        ->and($code)->toBe(0)
        ->and(collect($json['warnings'])->pluck('code')->all())->not->toContain('certificates_removed');
});
