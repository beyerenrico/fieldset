<?php

declare(strict_types=1);

namespace App\Domain\Instance\Adopt;

use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\Restore\AppKeys;
use App\Domain\Instance\Restore\LegacyEnv;
use App\Domain\Instance\State\InputGrammar;
use App\Domain\Instance\State\InstanceSettings;

/**
 * The files of a clone-based 1.0 install that `fsa adopt` reads (R46 as changed by R60, KTD20, plan U19, ADR 0087), mounted
 * read-only and never changed. Of its `.env` only the allow-list LegacyEnv::ADOPT is read: the APP keys, the URL and port,
 * the sender and SMTP server, the Compose project and files, and the bundled database's name and user (never DB_PASSWORD,
 * SMTP credentials, APP_DEBUG, SSO_* or TRUSTED_PROXIES). Refused with exit 7 and the reason, before anything changes:
 *
 * - a Compose override: COMPOSE_FILE naming anything but compose.yaml (the 1.0 production template sets exactly that), or
 *   an override file Compose would merge in without COMPOSE_FILE;
 * - an external database: a compose.yaml whose app roles do not reach the bundled `postgres` service (`DB_HOST: postgres`);
 * - another major than 1.x (package.json), and a `.env` without a valid APP_KEY (1.0 could not have run without one).
 */
final readonly class LegacyCheckout
{
    public const string COMPOSE_FILE = 'compose.yaml';

    /** Files Compose merges in on its own when COMPOSE_FILE is not set. */
    public const array OVERRIDES = ['compose.override.yaml', 'compose.override.yml', 'docker-compose.override.yaml', 'docker-compose.override.yml'];

    /** The project name 1.0's compose.yaml declares (`name:`). */
    public const string DEFAULT_PROJECT = 'form-schema-app';

    private const int MAX_BYTES = 1024 * 1024;

    /** @param  array<string, string>  $env  the allow-listed `.env` values */
    private function __construct(
        public string $path,
        public string $project,
        public string $version,
        private array $env,
    ) {}

    /**
     * @throws InvalidInput exit 2 for a path that is no readable checkout
     * @throws InstanceException exit 7 for what adopt refuses
     */
    public static function read(string $path): self
    {
        $path = rtrim($path, '/');
        if (! str_starts_with($path, '/') || ! is_dir($path) || ! is_file($path.'/'.self::COMPOSE_FILE) || ! is_file($path.'/.env')) {
            throw new InvalidInput("{$path} is not the directory of a 1.0 install: it needs compose.yaml and .env.", 'not_a_checkout', 'Pass the directory you run `docker compose` in for the 1.0 install (its absolute path).');
        }
        $env = LegacyEnv::read(self::file($path, '.env'), LegacyEnv::ADOPT);
        $compose = self::file($path, self::COMPOSE_FILE);
        self::assertNoOverride($path, $env['COMPOSE_FILE'] ?? null);
        self::assertBundledDatabase($compose);
        $checkout = new self($path, self::project($path, $env, $compose), self::version($path), $env);
        if (! AppKeys::valid($checkout->value('APP_KEY') ?? '')) {
            throw self::refused('The 1.0 .env holds no valid APP_KEY, so its encrypted data could not be read.', 'app_key_missing', 'Check APP_KEY in the checkout\'s .env (base64:… as php artisan key:generate writes it).');
        }

        return $checkout;
    }

    public function value(string $key): ?string
    {
        return $this->env[$key] ?? null;
    }

    /** @return array<string, string> the restore allow-list's values (LegacyEnv::ALLOWED), for the backup's `env` */
    public function allowed(): array
    {
        return array_intersect_key($this->env, array_flip(LegacyEnv::ALLOWED));
    }

    public function appKey(): string
    {
        return (string) $this->value('APP_KEY');
    }

    /** @return list<string> APP_PREVIOUS_KEYS that are valid keys */
    public function previousKeys(): array
    {
        $keys = array_map(trim(...), explode(',', (string) $this->value('APP_PREVIOUS_KEYS')));

        return array_values(array_filter($keys, static fn (string $key): bool => $key !== '' && AppKeys::valid($key)));
    }

    /** APP_PORT: the loopback port 1.0 publishes (8080 by default). */
    public function port(): int
    {
        $port = $this->value('APP_PORT');

        return $port !== null && ctype_digit($port) && InputGrammar::isPort((int) $port) ? (int) $port : InstanceSettings::DEFAULT_PORT;
    }

    /**
     * REDIS_DB: the Valkey database 1.0's queue uses (0 by default), where QueueDrain counts its jobs (review A5).
     *
     * @throws InstanceException exit 7 for a value that is not 0–15
     */
    public function redisDb(): int
    {
        $db = $this->value('REDIS_DB') ?? '0';
        if (preg_match('/^(\d|1[0-5])$/D', $db) !== 1) {
            throw self::refused("The 1.0 REDIS_DB [{$db}] is not a Valkey database number (0–15).", 'unsupported_redis_db', 'Set REDIS_DB to the database 1.0\'s queue uses, or remove it (0), and restart 1.0.');
        }

        return (int) $db;
    }

    public function url(): ?string
    {
        return $this->value('APP_URL');
    }

    /** @throws InstanceException exit 7 for a name the instance cannot use */
    public function database(): string
    {
        return $this->identifier('DB_DATABASE');
    }

    /** @throws InstanceException exit 7 */
    public function username(): string
    {
        return $this->identifier('DB_USERNAME');
    }

    /** @return array<string, string|int|null> what the journal keeps (no secret) */
    public function toArray(): array
    {
        return ['checkout' => $this->path, 'project' => $this->project, 'version' => $this->version, 'port' => $this->port(), 'url' => $this->url(),
            'database' => $this->database(), 'username' => $this->username()];
    }

    private function identifier(string $key): string
    {
        $value = $this->value($key) ?? InstanceSettings::DEFAULT_DATABASE;
        if (! InputGrammar::isIdentifier($value)) {
            throw self::refused("The 1.0 {$key} [{$value}] is not a name an instance can keep (lowercase letters, digits and _).", 'unsupported_database_name', 'Adopt supports the database and user names of the 1.0 template; rename them in Postgres first.');
        }

        return $value;
    }

    private static function assertNoOverride(string $path, ?string $composeFile): void
    {
        if ($composeFile !== null && ! in_array(trim($composeFile), [self::COMPOSE_FILE, './'.self::COMPOSE_FILE], true)) {
            throw self::refused("The 1.0 install runs with COMPOSE_FILE={$composeFile}: adopt copies only what compose.yaml of 1.0 defines.", 'compose_file_override', 'Set COMPOSE_FILE=compose.yaml (the production template\'s value) and restart 1.0 with `docker compose up -d`, or move what the override adds into instance settings after a fresh install.');
        }
        $found = array_values(array_filter(self::OVERRIDES, static fn (string $file): bool => file_exists($path.'/'.$file)));
        if ($composeFile === null && $found !== []) {
            throw self::refused('The checkout has '.implode(', ', $found).', which Compose merges into compose.yaml: adopt copies only what compose.yaml of 1.0 defines.', 'compose_file_override', 'Set COMPOSE_FILE=compose.yaml in .env (Compose then ignores the override) and restart 1.0, or remove the override.');
        }
    }

    private static function assertBundledDatabase(string $compose): void
    {
        preg_match_all('/^\s*DB_HOST:\s*["\']?([^"\'\s#]+)/m', $compose, $hosts);
        $external = array_values(array_filter($hosts[1], static fn (string $host): bool => $host !== 'postgres'));
        if ($hosts[1] === [] || $external !== [] || preg_match('/^\s{2}postgres:\s*$/m', $compose) !== 1) {
            throw self::refused('The 1.0 install does not use its bundled Postgres service'.($external === [] ? '' : ' (DB_HOST: '.implode(', ', array_unique($external)).')').': adopt copies the bundled database volume only.', 'external_database', 'Move the data with a 1.0 backup of your database instead: `install.sh --dir <new directory> --from-backup <backup> --accept-unauthenticated` takes a 1.0 backup.');
        }
    }

    /** @param  array<string, string>  $env */
    private static function project(string $path, array $env, string $compose): string
    {
        $name = $env['COMPOSE_PROJECT_NAME'] ?? (preg_match('/^name:\s*["\']?([a-z0-9][a-z0-9_-]*)["\']?\s*$/m', $compose, $match) === 1 ? $match[1] : null)
            ?? (string) preg_replace('/[^a-z0-9_-]/', '', strtolower(basename($path)));
        if (preg_match(ComposeProject::NAME_PATTERN, $name) !== 1) {
            throw self::refused("The Compose project name of the 1.0 install [{$name}] is not valid.", 'legacy_not_found', 'Check COMPOSE_PROJECT_NAME in the checkout\'s .env.');
        }

        return $name;
    }

    private static function version(string $path): string
    {
        $package = json_decode(is_file($path.'/package.json') ? self::file($path, 'package.json') : '', true);
        $version = is_array($package) && is_string($package['version'] ?? null) ? $package['version'] : null;
        if ($version === null || preg_match('/^1\.\d+\.\d+$/D', $version) !== 1) {
            throw self::refused('Adopt converts 1.x installs; this checkout is '.($version === null ? 'of an unknown version' : "version {$version}").'.', 'unsupported_version', 'Check out a 1.x release (git describe --tags), or install 2.0 with `fsa install`.');
        }

        return $version;
    }

    private static function file(string $path, string $name): string
    {
        $file = $path.'/'.$name;
        $contents = is_link($file) || ! is_readable($file) ? false : @file_get_contents($file, false, null, 0, self::MAX_BYTES);
        if ($contents === false) {
            throw new InvalidInput("The checkout's {$name} cannot be read.", 'checkout_unreadable', 'Run fsa as a user who can read the checkout (its .env is often mode 0600).');
        }

        return $contents;
    }

    private static function refused(string $message, string $code, string $hint): InstanceException
    {
        return new InstanceException($message, ExitCode::Incompatible, $code, $hint);
    }

    /** @return array<string, string> never the values (APP_KEY) */
    public function __debugInfo(): array
    {
        return ['path' => $this->path, 'project' => $this->project, 'version' => $this->version];
    }
}
