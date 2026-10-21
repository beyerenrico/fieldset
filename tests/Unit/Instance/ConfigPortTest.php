<?php

declare(strict_types=1);

use App\Domain\Instance\Lifecycle\ComposeFile;
use App\Domain\Instance\State\EnvFiles;
use Tests\Support\Instance\ConfigFixture;
use Tests\Support\Instance\TemporaryInstance;

/*
 * Plan U11 (R15, R32, ADR 0077): `fieldset config set port` in local mode (a URL change: --yes) and the rules around it:
 * the port must be free (exit 3), not the catcher's (exit 2), not in domain mode (exit 2); a failed smoke test after
 * the change reverts to the old port.
 */

beforeEach(function (): void {
    $this->config = new ConfigFixture;
});

afterEach(fn () => TemporaryInstance::cleanup());

it('moves the app to another loopback port and recreates app, queue and scheduler only', function (): void {
    [$code, $json] = $this->config->set('port', '8090', ['--yes' => true]);

    $compose = ComposeFile::document($this->config->instance->directory);
    $runtime = json_decode((string) $this->config->instance->directory->read('runtime/runtime.json'), true);
    expect($code)->toBe(0, json_encode($json))
        ->and($json['data'])->toMatchArray(['key' => 'port', 'from' => '8080', 'to' => '8090', 'url' => 'http://localhost:8090', 'mode' => 'local'])
        ->and($json['data']['services'])->toBe(['recreated' => ['app', 'queue', 'scheduler'], 'added' => [], 'removed' => []])
        ->and($compose['services']['app']['ports'][0]['published'])->toBe('8090')
        ->and($runtime['local_url'])->toBe('http://localhost:8090')
        ->and(new EnvFiles($this->config->instance->directory)->read('queue')['APP_URL'])->toBe('http://localhost:8090')
        ->and($this->config->curls)->toContain('http://app:8080/up');
});

it('changes nothing without a Docker call when the port is the current one', function (): void {
    $mark = $this->config->mark();

    [$code, $json] = $this->config->set('port', '8080');

    expect($code)->toBe(0)->and($json['changed'])->toBeFalse()->and($this->config->methodsSince($mark))->toBe([]);
});

it('needs --yes in local mode, where the port is part of the URL', function (): void {
    [$code, $json] = $this->config->set('port', '8090');

    expect($code)->toBe(6)
        ->and($json['errors'][0]['message'])->toContain('from http://localhost:8080 to http://localhost:8090');
});

it('refuses a taken port with exit 3 and changes nothing', function (): void {
    $this->config->takenLoopback = [8090];

    [$code, $json] = $this->config->set('port', '8090', ['--yes' => true]);

    expect($code)->toBe(3)
        ->and($json['errors'][0]['code'])->toBe('port_in_use')
        ->and($json['changed'])->toBeFalse()
        ->and($this->config->state()->setting('port'))->toBe(8080)
        ->and(ConfigFixture::phases($this->config->archivedJournal()))->toBe(['snapshot:completed', 'preflight:failed']);
});

it('refuses invalid ports and the catcher port with exit 2', function (string $value, string $error): void {
    [$code, $json] = $this->config->set('port', $value, ['--yes' => true]);

    expect($code)->toBe(2)->and($json['errors'][0]['code'])->toBe($error);
})->with([
    'zero' => ['0', 'invalid_value'],
    'too high' => ['70000', 'invalid_value'],
    'text' => ['eighty', 'invalid_value'],
    'catcher UI' => ['8025', 'port_conflict'],
]);

it('reverts to the old port when the smoke test fails after the change', function (): void {
    $calls = 0;
    $this->config->smokeFails = function (array $urls) use (&$calls): bool {
        return in_array('http://app:8080/up', $urls, true) && ++$calls === 1;
    };

    [$code, $json] = $this->config->set('port', '8090', ['--yes' => true]);

    expect($code)->toBe(5, json_encode($json))
        ->and($json['errors'][0]['code'])->toBe('smoke_failed')
        ->and($json['errors'][0]['hint'])->toContain('fieldset logs')->not->toContain('fieldset install')
        ->and($json['data'])->toMatchArray(['reverted' => true, 'url' => 'http://localhost:8080'])
        ->and($this->config->state()->setting('port'))->toBe(8080)
        ->and(ComposeFile::document($this->config->instance->directory)['services']['app']['ports'][0]['published'])->toBe('8080');
});

it('refuses the port in domain mode, where the edge publishes 80 and 443', function (): void {
    $this->config->set('domain', ConfigFixture::DOMAIN, ['--yes' => true]);

    [$code, $json] = $this->config->set('port', '8090', ['--yes' => true]);

    expect($code)->toBe(2)
        ->and($json['errors'][0]['code'])->toBe('not_in_this_mode')
        ->and($json['errors'][0]['hint'])->toContain('fieldset config set mode local');
});
