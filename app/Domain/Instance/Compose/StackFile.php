<?php

declare(strict_types=1);

namespace App\Domain\Instance\Compose;

use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\State\InstanceSecrets;
use App\Domain\Instance\State\InstanceState;
use App\Domain\Instance\State\RuntimeWriter;
use stdClass;

/**
 * The release's stack file (R18, ADR 0076): a compose file for Portainer, Coolify or plain `docker compose`
 * without `fsa`, configured only through environment variables. It is rendered by ComposeRenderer from a
 * proxy-mode (`port` setup) state with the release's image digests, so the two cannot drift, and then made
 * env-only:
 *
 * - secrets and the URL become `${VAR:?…}` (placeholders in the render are replaced, so every value derived from a
 *   secret follows, e.g. Valkey's `--requirepass`); mail, trusted proxies and hosts become `${VAR:-default}`;
 * - images stay pinned by digest, with the registry overridable (`${FSA_REGISTRY:-ghcr.io}/…@sha256:…`, E4);
 * - no env files, no runtime directory (the app then uses APP_URL, TRUSTED_HOSTS and TRUSTED_PROXIES), no instance
 *   labels; the app migrates on start (RUN_MIGRATIONS, there is no supervised one-off), and queue and scheduler wait
 *   for it to be healthy.
 *
 * The header documents the variables and what is missing without `fsa` (plan U9).
 */
final readonly class StackFile
{
    public const string NAME = 'compose.stack.yaml';

    /** Required variables => what to set them to. */
    public const array REQUIRED = [
        'APP_URL' => 'the public URL your proxy serves, e.g. https://forms.example.com',
        'APP_KEY' => 'base64:<32 random bytes, base64>, e.g. `echo "base64:$(openssl rand -base64 32)"`; keep it, it encrypts data',
        'DB_PASSWORD' => 'Postgres password (letters and digits), e.g. `openssl rand -hex 24`',
        'REDIS_PASSWORD' => 'Valkey password (letters and digits)',
        'MAILPIT_UI_PASSWORD' => 'password of the mail catcher UI, user "fsa" (letters and digits)',
    ];

    /** Optional variables => their default. */
    public const array OPTIONAL = [
        'FSA_REGISTRY' => 'the image registries in the file (set it for a mirror; digests stay pinned)',
        'APP_PORT' => '8080, published on 127.0.0.1 only',
        'MAILPIT_UI_PORT' => '8025, published on 127.0.0.1 only',
        'TRUSTED_PROXIES' => 'none; your proxy\'s address as the app sees it, never a subnet shared with other containers',
        'TRUSTED_HOSTS' => 'none; further host names besides the APP_URL host',
        'MAIL_HOST, MAIL_PORT' => 'the bundled catcher (mailpit:1025); set your SMTP server to deliver mail',
        'MAIL_SCHEME, MAIL_USERNAME, MAIL_PASSWORD' => 'empty (STARTTLS when offered; smtps for implicit TLS)',
        'MAIL_FROM_ADDRESS, MAIL_FROM_NAME' => 'forms@localhost, form-schema-app',
        'FSA_MAIL_CAPTURE' => 'true: admins see the capture banner; set false together with your SMTP server',
    ];

    private const string URL = 'https://stack-url.fsa.invalid';

    private const string UUID = '00000000-0000-4000-8000-000000000000';

    /** Placeholder secret => the variable that replaces it. */
    private const array SECRETS = [
        '__FSA_STACK_APP_KEY__' => 'APP_KEY', '__FSA_STACK_DB_PASSWORD__' => 'DB_PASSWORD',
        '__FSA_STACK_REDIS_PASSWORD__' => 'REDIS_PASSWORD', '__FSA_STACK_MAILPIT_UI_PASSWORD__' => 'MAILPIT_UI_PASSWORD',
    ];

    /** App-role variables that keep the rendered value as their default. */
    private const array OVERRIDABLE = ['MAIL_HOST', 'MAIL_PORT', 'MAIL_FROM_ADDRESS', 'MAIL_FROM_NAME', 'FSA_MAIL_CAPTURE'];

    /** App-role variables added with an empty default. */
    private const array ADDED = ['TRUSTED_PROXIES', 'TRUSTED_HOSTS', 'MAIL_SCHEME', 'MAIL_USERNAME', 'MAIL_PASSWORD'];

    /** Rendered values that mean nothing without `fsa` (runtime directory; SecureCookies decides Secure itself). */
    private const array DROPPED = ['FSA_RUNTIME_PATH', 'SESSION_SECURE_COOKIE'];

    /** Published loopback ports => the variable that overrides them. */
    private const array PORTS = ['app' => 'APP_PORT', 'mailpit' => 'MAILPIT_UI_PORT'];

    /** Migrations on a fresh database take a while, longer under emulation; failures before this do not count. */
    private const string APP_START_PERIOD = '300s';

    private const string INTERPOLATION = '/\$\{[A-Z][A-Z0-9_]*(?::[-?][^${}]*)?\}/';

    /** @param  array<string, mixed>  $document */
    private function __construct(public string $version, public array $document) {}

    /** @param  array<string, string>  $images  image role => `registry/repository@sha256:…`, as the state pins them */
    public static function render(array $images, string $version): self
    {
        $state = InstanceState::create(self::UUID, '/stack', 0, 0, 0, 'proxy')
            ->withRelease($version, $images)
            ->withUrl(self::URL)
            ->withSettings(['proxy' => 'port']);
        $secrets = array_keys(self::SECRETS);
        $rendered = new ComposeRenderer()->render($state, new InstanceSecrets($secrets[0], $secrets[1], $secrets[2], $secrets[3], 'unused'));
        $services = [];
        foreach ($rendered->document['services'] as $name => $service) {
            $services[$name] = self::service((string) $name, $service, $rendered->environments[$name] ?? []);
        }
        $document = [
            'services' => $services,
            'networks' => self::unlabelled($rendered->document['networks']),
            'volumes' => self::unlabelled($rendered->document['volumes']),
        ];
        self::assertSafe($document);

        return new self($version, $document);
    }

    public function yaml(): string
    {
        return $this->header().YamlEmitter::emit($this->document);
    }

    public function writeTo(string $path): void
    {
        if (@file_put_contents($path, $this->yaml()) === false) {
            throw new InstanceException("Cannot write the stack file to [{$path}].");
        }
    }

    /**
     * @param  array<string, mixed>  $service
     * @param  array<string, string>  $environment
     * @return array<string, mixed>
     */
    private static function service(string $name, array $service, array $environment): array
    {
        $result = [];
        foreach ($service as $key => $value) {
            $result[$key] = match ($key) {
                'image' => self::image((string) $value),
                'env_file' => self::environment($name, $environment),
                'labels' => array_diff_key((array) $value, [Labels::INSTANCE => true]),
                'volumes' => array_values(array_filter((array) $value, static fn (mixed $volume): bool => ! is_array($volume) || ($volume['target'] ?? null) !== RuntimeWriter::CONTAINER_PATH)),
                'ports' => self::ports($name, (array) $value),
                default => $value,
            };
        }
        $result = ['environment' => $result['env_file'], ...array_diff_key($result, ['env_file' => true])];
        if ($name === 'app') {
            $result['healthcheck'] = [...(array) $result['healthcheck'], 'start_period' => self::APP_START_PERIOD];
        }
        if (in_array($name, ['queue', 'scheduler'], true)) {
            $result['depends_on'] = [...(array) $result['depends_on'], 'app' => ['condition' => 'service_healthy']];
        }

        return ['image' => $result['image'], ...$result];
    }

    /**
     * @param  array<string, string>  $environment
     * @return array<string, string>
     */
    private static function environment(string $service, array $environment): array
    {
        $result = [];
        foreach (array_diff_key($environment, array_flip(self::DROPPED)) as $key => $value) {
            $result[$key] = in_array($key, self::OVERRIDABLE, true) ? '${'.$key.':-'.$value.'}' : self::variables($value);
        }
        if (! in_array($service, [ComposeRenderer::ONE_OFF_SERVICE, 'queue', 'scheduler'], true)) {
            return $result;
        }
        foreach (self::ADDED as $key) {
            $result[$key] ??= '${'.$key.':-}';
        }

        return $service === 'app' ? [...$result, 'RUN_MIGRATIONS' => 'true'] : $result;
    }

    /** Placeholder secrets and the placeholder URL become required variables. */
    private static function variables(string $value): string
    {
        $replacements = ['${APP_URL:?set APP_URL to the public URL}' => self::URL];
        foreach (self::SECRETS as $placeholder => $variable) {
            $replacements['${'.$variable.':?set '.$variable.', see the header}'] = $placeholder;
        }

        return str_replace(array_values($replacements), array_keys($replacements), $value);
    }

    /** `registry/repository@digest` → `${FSA_REGISTRY:-registry}/repository@digest`. */
    private static function image(string $reference): string
    {
        [$registry, $rest] = explode('/', $reference, 2);

        return '${FSA_REGISTRY:-'.$registry.'}/'.$rest;
    }

    /**
     * @param  array<array-key, mixed>  $ports
     * @return list<mixed>
     */
    private static function ports(string $service, array $ports): array
    {
        $variable = self::PORTS[$service] ?? null;

        return array_values(array_map(static fn (mixed $port): mixed => is_array($port) && $variable !== null
            ? [...$port, 'published' => '${'.$variable.':-'.$port['published'].'}']
            : $port, $ports));
    }

    /**
     * @param  array<string, mixed>  $resources
     * @return array<string, mixed>
     */
    private static function unlabelled(array $resources): array
    {
        return array_map(static function (mixed $resource): mixed {
            $rest = is_array($resource) ? array_diff_key($resource, ['labels' => true]) : [];

            return $rest === [] ? new stdClass : $rest;
        }, $resources);
    }

    /**
     * Every `$` is one of the variables above, every placeholder is gone, and nothing mounts the Docker socket or
     * reads an env file: a failure is a renderer bug (exit 1).
     *
     * @param  array<string, mixed>  $document
     */
    private static function assertSafe(array $document): void
    {
        $json = json_encode($document, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $stripped = (string) preg_replace(self::INTERPOLATION, '', $json);
        foreach (['$' => 'an unknown interpolation', '__FSA_STACK_' => 'a placeholder', self::URL => 'the placeholder URL', 'docker.sock' => 'the Docker socket', 'env_file' => 'an env file'] as $needle => $what) {
            if (str_contains($stripped, $needle)) {
                throw new InstanceException("The stack file would contain {$what}.", ExitCode::InternalError, 'unsafe_stack_file');
            }
        }
    }

    private function header(): string
    {
        $lines = [
            "form-schema-app {$this->version} stack file for Portainer, Coolify or `docker compose` (R18, ADR 0076).",
            'Generated from the same renderer as `fsa install --behind-proxy port`; do not edit the digests by hand.',
            '',
            'Configured only through environment variables (stack variables in Portainer or Coolify, or the shell):',
            ...array_map(static fn (string $name, string $what): string => "  {$name} (required): {$what}", array_keys(self::REQUIRED), self::REQUIRED),
            ...array_map(static fn (string $name, string $default): string => "  {$name}: default {$default}", array_keys(self::OPTIONAL), self::OPTIONAL),
            '',
            'Put a TLS-terminating reverse proxy in front: publish 127.0.0.1:APP_PORT to a proxy on the host, or attach',
            'the app (only the app) to your proxy\'s network and route to app:8080 (Coolify does this for you).',
            'The app migrates the database on start. Create the first organization and owner once, in the directory of',
            'this file and with the same variables set (Compose needs them for every command; in Portainer or Coolify run',
            'the `php artisan app:install …` part in the app container\'s console):',
            '  docker compose -f compose.stack.yaml exec app php artisan app:install --organization="My Org" --name="Your Name" --email=you@example.com --owner-link',
            'It prints a one-time sign-in link (60 minutes) for the owner.',
            '',
            'Missing without fsa (install with fsa to get them):',
            '  - no fsa commands: no doctor, status, logs, login-link, config set or uninstall;',
            '  - no upgrade supervisor: no pre-upgrade backup, health gate or automatic rollback. Upgrade by hand: back up,',
            '    replace this file with the new release\'s, redeploy; migrations are forward-only;',
            '  - no backups through fsa: no scheduled, verified or encrypted backups; dump Postgres and the app-storage volume yourself;',
            '  - no runtime state: a URL change needs APP_URL and a redeploy; no operation gate, no quick tunnel, no local CA, no edge;',
            '  - fixed resource limits (the 2 GB profile) and no instance labels.',
        ];

        return implode("\n", array_map(static fn (string $line): string => rtrim('# '.$line), $lines))."\n";
    }
}
