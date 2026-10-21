<?php

declare(strict_types=1);

namespace App\Domain\Instance\Demo;

use App\Domain\Instance\Console\FieldsetContext;
use App\Domain\Instance\Lifecycle\OneOffResult;
use App\Domain\Instance\Output\CommandResult;
use SensitiveParameter;

/**
 * What `app:demo-seed` or `app:demo-passwords --rotate` answered, as `fieldset` hands it out (R29, R50, ADR 0081):
 * `data.demo` describes the demo (organization, users, password mode, why the instance counts as reachable), and
 * random passwords leave only as one-time secrets named `demo_password:<email>` (envelope `secrets` or
 * `--secrets-file`), never in data, warnings, the journal or progress. The documented default `password` is public:
 * it is reported as `data.demo.default_password` and never registered as a secret (the redactor would otherwise blank
 * the word "password" in every message).
 */
final readonly class DemoCredentials
{
    public const string SECRET_PREFIX = 'demo_password:';

    /** The documented demo password (App\Domain\Identity\Demo\DefaultDemoPassword::PASSWORD): public, never a secret. */
    public const string DEFAULT_PASSWORD = 'password';

    /** The demo organization's owner (App\Domain\Identity\Demo\DemoAccounts::OWNER): `fieldset demo` signs in as this account. */
    public const string OWNER = 'owner@demo.test';

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $passwords  email => random password
     */
    private function __construct(public array $data, #[SensitiveParameter] private array $passwords) {}

    /** @param  list<string>  $exposure */
    public static function fromSeed(OneOffResult $result, array $exposure): self
    {
        $data = $result->data;
        $seeded = ($data['seeded'] ?? false) === true;
        $defaults = is_int($data['default_password_users'] ?? null) ? $data['default_password_users'] : 0;
        // An existing loopback demo whose accounts still have the documented password keeps telling it.
        $mode = is_string($data['passwords'] ?? null) ? $data['passwords'] : ($exposure === [] && $defaults > 0 ? DemoPasswords::DEFAULT : null);

        return new self([
            'organization' => is_array($data['organization'] ?? null) && is_string($data['organization']['name'] ?? null) ? $data['organization']['name'] : null,
            'seeded' => $seeded,
            'passwords' => $mode,
            ...($mode === DemoPasswords::DEFAULT ? ['default_password' => self::DEFAULT_PASSWORD] : []),
            'exposure' => $exposure,
            'users' => self::users($data['users'] ?? null),
            'login_url' => is_string($data['login_url'] ?? null) ? $data['login_url'] : null,
            ...($seeded ? [] : ['default_password_users' => $defaults]),
        ], $result->secretsWithPrefix(self::SECRET_PREFIX));
    }

    /** @param  list<string>  $exposure */
    public static function fromRotation(OneOffResult $result, array $exposure): self
    {
        $data = $result->data;
        $users = array_values(array_filter(is_array($data['users'] ?? null) ? $data['users'] : [], is_string(...)));
        $passwords = $result->secretsWithPrefix(self::SECRET_PREFIX);

        return new self([
            'rotated' => $passwords !== [],
            'passwords' => $passwords === [] ? null : DemoPasswords::RANDOM,
            'exposure' => $exposure,
            'users' => array_map(static fn (string $email): array => ['email' => $email], $users),
            'sessions_ended' => is_int($data['sessions_ended'] ?? null) ? $data['sessions_ended'] : 0,
            'tokens_revoked' => is_int($data['tokens_revoked'] ?? null) ? $data['tokens_revoked'] : 0,
        ], $passwords);
    }

    /** This seeding followed by a rotation: the rotation's passwords replace the documented one. */
    public function withRotation(self $rotation): self
    {
        $data = array_diff_key($this->data, ['default_password' => true]);

        return new self([...$data, 'passwords' => $rotation->data['passwords'], 'rotated' => $rotation->data['rotated'], 'sessions_ended' => $rotation->data['sessions_ended'], 'tokens_revoked' => $rotation->data['tokens_revoked']], $rotation->passwords);
    }

    public function seeded(): bool
    {
        return $this->data['seeded'] ?? false;
    }

    public function changed(): bool
    {
        return ($this->data['seeded'] ?? false) || ($this->data['rotated'] ?? false);
    }

    /** Existing demo accounts that still sign in with `password` (only known when the demo existed already). */
    public function defaultPasswordUsers(): int
    {
        return is_int($this->data['default_password_users'] ?? null) ? $this->data['default_password_users'] : 0;
    }

    /** @return array<string, mixed> what the journal may keep: no password, no e-mail list */
    public function journal(): array
    {
        return array_filter([
            'seeded' => $this->data['seeded'] ?? null,
            'rotated' => $this->data['rotated'] ?? null,
            'passwords' => $this->data['passwords'] ?? null,
            'users' => count($this->data['users'] ?? []),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** `data.demo` plus one secret per random password; `delivered` says where the passwords went. */
    public function apply(CommandResult $result, FieldsetContext $context, bool $toFile): CommandResult
    {
        $delivered = match (true) {
            $this->passwords !== [] => $toFile ? 'secrets_file' : 'secrets',
            ($this->data['passwords'] ?? null) === DemoPasswords::DEFAULT => 'data',
            default => null,
        };
        $result = $result->withData(['demo' => [...$this->data, 'delivered' => $delivered]]);
        foreach ($this->passwords as $email => $password) {
            $result = $result->withSecret($context->secret(self::SECRET_PREFIX.$email, $password));
        }

        return $result;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['passwords' => '[redacted]'];
    }

    /** @return list<array{email: string, name: string|null, roles: list<string>}> */
    private static function users(mixed $users): array
    {
        $list = [];
        foreach (is_array($users) ? $users : [] as $user) {
            if (is_array($user) && is_string($user['email'] ?? null)) {
                $roles = array_values(array_filter(is_array($user['roles'] ?? null) ? $user['roles'] : [], is_string(...)));
                $list[] = ['email' => $user['email'], 'name' => is_string($user['name'] ?? null) ? $user['name'] : null, 'roles' => $roles];
            }
        }

        return $list;
    }
}
