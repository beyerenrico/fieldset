<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Support\ActorUid;
use App\Console\Support\JsonResult;
use App\Domain\Identity\Actions\CreateOrganization;
use App\Domain\Identity\Actions\IssueOwnerLink;
use App\Domain\Identity\Actions\SyncPermissionCatalog;
use App\Domain\Identity\LoginLinks\IssuedLoginLink;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Actions\GrantPlatformAdmin;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use InvalidArgumentException;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Creates the first organization and its owner (BUILD_PROMPT §11 Operability; 2.0: R12, R26, R27, R50, KTD21).
 * The owner gets the platform operator role (ADR 0041). Non-interactive use (`fsa install` runs it as a
 * socket-less one-off, KTD25): `--password-file`, or `--owner-link` for no password at all and a one-time first
 * sign-in link instead (password-reset broker, 60 minutes). `--password` still works but is deprecated (the value
 * shows in the process list). `--json` prints one JSON document; the link appears only under `secrets`, a
 * password never.
 */
#[Description('Create the first organization and its owner account')]
#[Signature('app:install
        {--organization= : Organization name}
        {--name= : Owner name}
        {--email= : Owner email}
        {--password= : Deprecated (visible in the process list): use --password-file}
        {--password-file= : Read the owner password from this file}
        {--owner-link : No password: issue a one-time first sign-in link (60 minutes) instead}
        {--locale=de : Organization locale (de|en)}
        {--actor-uid= : Host UID of the operator running fsa (audit)}
        {--json : Print one JSON document on stdout}')]
final class InstallCommand extends Command
{
    private const string NAME = 'app:install';

    /** @var list<array{0: string, 1: string, 2: string|null}> */
    private array $warnings = [];

    public function handle(SyncPermissionCatalog $catalog, CreateOrganization $createOrganization, GrantPlatformAdmin $grant, IssueOwnerLink $ownerLink): int
    {
        try {
            $hostUid = ActorUid::from($this->option('actor-uid'));
            if (Organization::query()->withTrashed()->exists()) {
                return $this->alreadyInstalled();
            }
            $data = $this->collect();
        } catch (InvalidArgumentException $e) {
            return $this->refuse('invalid_input', $e->getMessage());
        }

        // One transaction (U23 review A7): a failure never leaves an owner without the operator role or an organization
        // without its owner link, which a repeated install (or `fsa reset`) would then take for a finished one.
        [$organization, $owner, $issued] = DB::transaction(function () use ($catalog, $createOrganization, $grant, $ownerLink, $data, $hostUid): array {
            $catalog->handle();
            $owner = $this->owner($data);
            $organization = $createOrganization->handle(['name' => $data['organization'], 'locale' => $data['locale']], $owner);
            $grant->handle($owner);

            return [$organization, $owner, $data['password'] === null ? $ownerLink->handle($owner, $hostUid) : null];
        });

        return $this->json() ? $this->jsonResult($organization, $owner, $issued)->emit($this) : $this->human($organization, $owner, $issued);
    }

    /**
     * @return array{organization: string, name: string, email: string, password: string|null, locale: string}
     *
     * @throws InvalidArgumentException
     */
    private function collect(): array
    {
        $data = [
            'organization' => $this->stringOption('organization') ?? $this->promptFor('Organization name'),
            'name' => $this->stringOption('name') ?? $this->promptFor('Owner name'),
            'email' => Str::lower($this->stringOption('email') ?? $this->promptFor('Owner email')),
            'password' => $this->password(),
            'locale' => $this->stringOption('locale') ?? 'de',
        ];
        $validator = Validator::make($data, [
            'organization' => ['required', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['nullable', Password::min(10)],
            'locale' => ['required', 'in:de,en'],
        ]);
        if ($validator->fails()) {
            throw new InvalidArgumentException(implode(' ', $validator->errors()->all()));
        }

        return $data;
    }

    /**
     * The owner password, or null for `--owner-link`. Never from a flag value without a deprecation warning.
     *
     * @throws InvalidArgumentException
     */
    private function password(): ?string
    {
        $file = $this->stringOption('password-file');
        $flag = $this->stringOption('password');
        $sources = array_filter([$file !== null, $flag !== null, $this->option('owner-link') === true]);
        if (count($sources) > 1) {
            throw new InvalidArgumentException('Use only one of --password-file, --password and --owner-link.');
        }
        if ($this->option('owner-link') === true) {
            return null;
        }
        if ($file !== null) {
            return $this->readPasswordFile($file);
        }
        if ($flag !== null) {
            $this->addWarning('password_option_deprecated', 'The --password option is deprecated: the value is visible in the process list and the shell history.', 'Use --password-file, or --owner-link for a one-time sign-in link.');

            return $flag;
        }
        if (! $this->interactive()) {
            throw new InvalidArgumentException('An owner password is required: pass --password-file or --owner-link.');
        }

        return password('Owner password', required: true);
    }

    /** @throws InvalidArgumentException */
    private function readPasswordFile(string $path): string
    {
        $content = is_file($path) && is_readable($path) ? file_get_contents($path) : false;
        if ($content === false) {
            throw new InvalidArgumentException("The password file [{$path}] cannot be read.");
        }

        return rtrim($content, "\r\n");
    }

    /** @param  array{organization: string, name: string, email: string, password: string|null, locale: string}  $data */
    private function owner(array $data): User
    {
        $owner = User::query()->firstOrCreate(['email' => $data['email']], [
            'name' => $data['name'],
            'password' => $data['password'] ?? Str::password(64), // owner link: a password nobody knows
            'locale' => $data['locale'],
        ]);
        if ($data['password'] === null && $owner->wasRecentlyCreated) {
            $owner->forceFill(['password_known' => false])->saveQuietly(); // set with the first link (ADR 0039)
        }
        $owner->markEmailAsVerified();

        return $owner;
    }

    private function jsonResult(Organization $organization, User $owner, ?IssuedLoginLink $issued): JsonResult
    {
        $result = JsonResult::ok(self::NAME, [
            'organization' => ['id' => $organization->id, 'name' => $organization->name, 'slug' => $organization->slug],
            'owner' => ['id' => $owner->id, 'email' => $owner->email, 'name' => $owner->name, 'operator' => true, 'password_set' => ! $issued instanceof IssuedLoginLink],
            'login_url' => url('/admin/login'),
        ], changed: true);
        foreach ($this->warnings as [$code, $message, $hint]) {
            $result = $result->withWarning($code, $message, $hint);
        }

        return $issued instanceof IssuedLoginLink ? $result->withSecret('sign_in_link', $issued->url, $issued->link->expires_at) : $result;
    }

    private function human(Organization $organization, User $owner, ?IssuedLoginLink $issued): int
    {
        if (! $issued instanceof IssuedLoginLink) {
            $this->components->info("Organization [{$organization->name}] created. Sign in at ".url('/admin/login')." as {$owner->email}.");

            return self::SUCCESS;
        }
        $until = $issued->link->expires_at->toImmutable()->utc()->format('Y-m-d H:i');
        $this->components->info("Organization [{$organization->name}] created. One-time sign-in link for {$owner->email}, single use, valid until {$until} UTC:");
        $this->line($issued->url);

        return self::SUCCESS;
    }

    private function alreadyInstalled(): int
    {
        $message = 'Already installed: an organization exists. Nothing was changed.';
        if ($this->json()) {
            return JsonResult::ok(self::NAME)->withWarning('already_installed', $message)->emit($this);
        }
        $this->components->warn($message);

        return self::SUCCESS;
    }

    private function refuse(string $code, string $message): int
    {
        if ($this->json()) {
            return JsonResult::failed(self::NAME, $code, $message)->emit($this);
        }
        $this->components->error($message);

        return self::FAILURE;
    }

    private function addWarning(string $code, string $message, ?string $hint): void
    {
        $this->warnings[] = [$code, $message, $hint];
        if (! $this->json()) {
            $this->components->warn($message.' '.$hint);
        }
    }

    /** @throws InvalidArgumentException when a value is missing and nobody can be asked */
    private function promptFor(string $label): string
    {
        if (! $this->interactive()) {
            throw new InvalidArgumentException("Missing value: {$label}.");
        }

        return text($label, required: true);
    }

    private function interactive(): bool
    {
        return ! $this->json() && $this->input->isInteractive();
    }

    private function json(): bool
    {
        return $this->option('json') === true;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
