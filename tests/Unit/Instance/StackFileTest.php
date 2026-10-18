<?php

declare(strict_types=1);

use App\Domain\Instance\Compose\ComposeRenderer;
use App\Domain\Instance\Compose\StackFile;
use App\Domain\Instance\Compose\YamlEmitter;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Release\ImagePin;
use App\Domain\Instance\Release\ReleaseInputs;
use App\Domain\Instance\Release\ReleaseVersion;
use App\Domain\Instance\State\RuntimeWriter;
use Tests\Support\Instance\ComposeFixtures;

/*
 * Plan U9 (R18, ADR 0076): the release's stack file for Portainer, Coolify and plain `docker compose`. It is
 * rendered from the same renderer as proxy mode, so the drift test compares it with a proxy-mode render; it is
 * configured only through environment variables, and its header documents them and what is missing without
 * fsa. J3 starts it with real Docker; the ComposeConfig journey parses it with Compose.
 */

const STACK_INTERPOLATION = '/\$\{([A-Z][A-Z0-9_]*)(?::([-?])([^${}]*))?\}/';

function stackFile(): StackFile
{
    return StackFile::render(ComposeFixtures::digests(), '2.0.0-alpha.2');
}

/** @return array<string, string> service => image with `${FSA_REGISTRY:-x}` resolved to x */
function stackImages(StackFile $stack): array
{
    return array_map(fn (array $service): string => (string) preg_replace('/^\$\{FSA_REGISTRY:-([^}]+)\}/', '$1', $service['image']), $stack->document['services']);
}

/** @return array<string, string> variable => `-` (default) or `?` (required) */
function stackVariables(StackFile $stack): array
{
    preg_match_all(STACK_INTERPOLATION, json_encode($stack->document, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $matches, PREG_SET_ORDER);
    $variables = [];
    foreach ($matches as $match) {
        $variables[$match[1]] = $match[2] ?? '';
    }
    ksort($variables);

    return $variables;
}

it('lists the same services and images as the proxy-mode render (drift test)', function (): void {
    $stack = stackFile();
    $render = new ComposeRenderer()->render(ComposeFixtures::proxyState('port'), ComposeFixtures::secrets());
    $renderImages = array_map(fn (array $service): string => $service['image'], $render->document['services']);

    expect(array_keys($stack->document['services']))->toBe($render->services())
        ->and(stackImages($stack))->toBe($renderImages)
        ->and(array_keys($stack->document['volumes']))->toBe(array_keys($render->document['volumes']))
        ->and(array_keys($stack->document['networks']))->toBe(array_keys($render->document['networks']));
    foreach ($render->document['services'] as $name => $service) {
        expect($stack->document['services'][$name]['mem_limit'])->toBe($service['mem_limit'], $name)
            ->and($stack->document['services'][$name]['networks'])->toBe($service['networks'], $name)
            ->and($stack->document['services'][$name]['command'] ?? null)->toBe($service['command'] ?? null, $name);
    }
});

it('pins every image of a release by digest from the manifest pins, with an overridable registry', function (): void {
    $inputs = ReleaseInputs::fromFile(base_path('distribution/release.json'));
    $pins = ['app' => $inputs->appImage(ReleaseVersion::parse('2.0.0'), 'sha256:'.str_repeat('a', 64)), ...$inputs->images];
    $stack = StackFile::render(array_map(fn (ImagePin $pin): string => $pin->pullReference(), $pins), '2.0.0');
    $images = array_values(array_unique(array_map(fn (array $service): string => $service['image'], $stack->document['services'])));

    expect($images)->toEqualCanonicalizing([
        '${FSA_REGISTRY:-ghcr.io}/beyerenrico/form-schema-app@sha256:'.str_repeat('a', 64),
        '${FSA_REGISTRY:-'.$pins['postgres']->registry.'}/'.$pins['postgres']->repository.'@'.$pins['postgres']->digest,
        '${FSA_REGISTRY:-'.$pins['valkey']->registry.'}/'.$pins['valkey']->repository.'@'.$pins['valkey']->digest,
        '${FSA_REGISTRY:-'.$pins['gotenberg']->registry.'}/'.$pins['gotenberg']->repository.'@'.$pins['gotenberg']->digest,
        '${FSA_REGISTRY:-'.$pins['mailpit']->registry.'}/'.$pins['mailpit']->repository.'@'.$pins['mailpit']->digest,
    ])->and($stack->yaml())->toContain('# form-schema-app 2.0.0 stack file');
});

it('is configured only through environment variables: no env file, runtime mount, socket or instance label', function (): void {
    $stack = stackFile();
    $json = json_encode($stack->document, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    expect($json)->not->toContain('env_file')
        ->not->toContain('./env/')
        ->not->toContain(RuntimeWriter::CONTAINER_PATH)
        ->not->toContain('FSA_RUNTIME_PATH')
        ->not->toContain('docker.sock')
        ->not->toContain(Labels::INSTANCE)
        ->not->toContain('__FSA_STACK_')
        ->not->toContain('fsa.invalid')
        ->and($stack->document)->not->toHaveKey('name');
    foreach ($stack->document['services'] as $name => $service) {
        expect($service['environment'])->toBeArray()->not->toBeEmpty()
            ->and(array_key_first($service))->toBe('image', $name)
            ->and($service['labels'] ?? [])->toBe([Labels::ROLE => $name]);
    }
    foreach ([...$stack->document['volumes'], ...$stack->document['networks']] as $resource) {
        expect((array) $resource)->not->toHaveKey('labels');
    }
});

it('documents exactly the variables it uses: secrets and the URL required, everything else with a default', function (): void {
    $documented = array_merge(...array_map(fn (string $names): array => explode(', ', $names), array_keys(StackFile::OPTIONAL)));
    $variables = stackVariables(stackFile());

    expect(array_keys($variables))->toEqualCanonicalizing([...array_keys(StackFile::REQUIRED), ...$documented])
        ->and(array_keys(array_filter($variables, fn (string $kind): bool => $kind === '?')))->toEqualCanonicalizing(array_keys(StackFile::REQUIRED));
    $header = array_filter(explode("\n", stackFile()->yaml()), fn (string $line): bool => str_starts_with($line, '#   '));
    foreach ([...array_keys(StackFile::REQUIRED), ...$documented] as $variable) {
        expect(array_filter($header, fn (string $line): bool => preg_match('/^#   (?:[A-Z_]+, )*'.$variable.'\b/', $line) === 1))->not->toBeEmpty($variable);
    }
});

it('derives every secret-bearing value from the variables, Valkey and Mailpit included', function (): void {
    $services = stackFile()->document['services'];

    expect($services['app']['environment'])->toMatchArray([
        'APP_KEY' => '${APP_KEY:?set APP_KEY, see the header}',
        'APP_URL' => '${APP_URL:?set APP_URL to the public URL}',
        'DB_PASSWORD' => '${DB_PASSWORD:?set DB_PASSWORD, see the header}',
        'REDIS_PASSWORD' => '${REDIS_PASSWORD:?set REDIS_PASSWORD, see the header}',
        'TRUSTED_PROXIES' => '${TRUSTED_PROXIES:-}',
        'MAIL_HOST' => '${MAIL_HOST:-mailpit}',
        'MAIL_PORT' => '${MAIL_PORT:-1025}',
    ])->and($services['app']['environment'])->not->toHaveKey('SESSION_SECURE_COOKIE')
        ->and($services['postgres']['environment']['POSTGRES_PASSWORD'])->toBe('${DB_PASSWORD:?set DB_PASSWORD, see the header}')
        ->and($services['redis']['environment']['VALKEY_EXTRA_FLAGS'])->toBe('--requirepass ${REDIS_PASSWORD:?set REDIS_PASSWORD, see the header}')
        ->and($services['mailpit']['environment']['MP_UI_AUTH'])->toBe('fsa:${MAILPIT_UI_PASSWORD:?set MAILPIT_UI_PASSWORD, see the header}')
        ->and($services['gotenberg']['environment'])->not->toHaveKey('APP_KEY')
        ->and($services['gotenberg']['environment'])->not->toHaveKey('TRUSTED_PROXIES');
});

it('migrates on app start and starts queue and scheduler only once the app is healthy', function (): void {
    $services = stackFile()->document['services'];

    expect($services['app']['environment']['RUN_MIGRATIONS'])->toBe('true')
        ->and($services['app']['healthcheck']['start_period'])->toBe('300s')
        ->and($services['queue']['depends_on']['app'])->toBe(['condition' => 'service_healthy'])
        ->and($services['scheduler']['depends_on']['app'])->toBe(['condition' => 'service_healthy']);
    foreach (['queue', 'scheduler', 'postgres', 'redis', 'gotenberg', 'mailpit'] as $service) {
        expect($services[$service]['environment'])->not->toHaveKey('RUN_MIGRATIONS');
    }
});

it('publishes the app and the catcher UI on loopback ports that variables can move', function (): void {
    $services = stackFile()->document['services'];

    expect($services['app']['ports'])->toBe([['target' => 8080, 'published' => '${APP_PORT:-8080}', 'host_ip' => '127.0.0.1', 'protocol' => 'tcp']])
        ->and($services['mailpit']['ports'])->toBe([['target' => 8025, 'published' => '${MAILPIT_UI_PORT:-8025}', 'host_ip' => '127.0.0.1', 'protocol' => 'tcp']]);
});

it('names in its header what is missing without fsa', function (): void {
    $header = implode("\n", array_filter(explode("\n", stackFile()->yaml()), fn (string $line): bool => str_starts_with($line, '#')));

    expect($header)->toContain('no upgrade supervisor')
        ->toContain('no backups through fsa')
        ->toContain('no fsa commands')
        ->toContain('  docker compose -f compose.stack.yaml exec app php artisan app:install ')
        ->toContain('with the same variables set')
        ->toContain('2.0.0-alpha.2')
        ->and(stackFile()->yaml())->toStartWith('# form-schema-app')
        ->and(stackFile()->yaml())->toContain("\nservices:\n  app:\n    image: \"\${FSA_REGISTRY:-registry.test:5000}/fsa/app@sha256:");
});

it('writes the file and reports a path it cannot write', function (): void {
    $path = sys_get_temp_dir().'/fsa-stack-'.bin2hex(random_bytes(4)).'.yaml';
    stackFile()->writeTo($path);

    expect((string) file_get_contents($path))->toBe(stackFile()->yaml())
        ->and(fn () => stackFile()->writeTo('/nonexistent-dir/'.StackFile::NAME))->toThrow(InstanceException::class, 'Cannot write the stack file');
    unlink($path);
});

it('emits block YAML whose scalars are JSON string literals and whose ambiguous keys are quoted', function (): void {
    $yaml = YamlEmitter::emit([
        'services' => ['app' => ['image' => 'a/b@sha256:1', 'command' => ['php', 'artisan'], 'ports' => [['target' => 8080, 'published' => '8080']]]],
        'on' => true, 'y' => null, 'with space' => "quote \" and\nline", 'nested' => [['a', 'b'], ['c']],
        'empty_list' => [], 'empty_map' => new stdClass, 'labels' => ['traefik.http.routers.x.rule' => 'Host(`a.example`)'],
    ]);

    expect($yaml)->toBe(<<<'YAML'
        services:
          app:
            image: "a/b@sha256:1"
            command:
              - "php"
              - "artisan"
            ports:
              - target: 8080
                published: "8080"
        "on": true
        "y": null
        "with space": "quote \" and\nline"
        nested:
          - - "a"
            - "b"
          - - "c"
        empty_list: []
        empty_map: {}
        labels:
          traefik.http.routers.x.rule: "Host(`a.example`)"

        YAML);
});
