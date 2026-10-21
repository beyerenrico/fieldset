<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Support\ActorUid;
use App\Console\Support\JsonResult;
use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Actions\SyncPermissionCatalog;
use App\Domain\Identity\Demo\DefaultDemoPassword;
use App\Domain\Identity\Demo\DefaultPasswordUsers;
use App\Domain\Identity\Demo\DemoAccounts;
use App\Domain\Identity\Demo\DemoPasswordSource;
use App\Domain\Identity\Demo\RandomDemoPasswords;
use App\Domain\Identity\Models\Organization;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * The app side of `fieldset install --demo` and `fieldset demo` (R29, R30, KTD25, ADR 0081): runs DemoSeeder (which
 * DatabaseSeeder skips in production) in a socket-less one-off. `--passwords=random` gives every demo user it creates a
 * random password, printed once under `secrets` as `demo_password:<email>`; `default` keeps the documented `password`,
 * which is public and therefore reported in `data.default_password`, never as a secret. The seeding is one
 * transaction, and jobs it queues wait for the commit, so a failure leaves no half demo behind. An existing demo
 * organization changes nothing (`already_seeded`) and reports how many demo accounts still have `password`.
 */
#[Description('Add the demo organization, forms, workflow and users (fieldset install --demo, fieldset demo)')]
#[Signature('app:demo-seed
        {--passwords=default : default (the documented password "password": loopback-only instances) or random (printed once)}
        {--actor-uid= : Host UID of the operator running fieldset (audit)}
        {--json : Print one JSON document on stdout}')]
final class DemoSeedCommand extends Command
{
    public const string EVENT = 'instance.demo_seeded';

    public const string SECRET_PREFIX = 'demo_password:';

    private const string NAME = 'app:demo-seed';

    private const array MODES = ['default', 'random'];

    public function handle(SyncPermissionCatalog $catalog, AuditLogger $audit, DefaultPasswordUsers $defaults): int
    {
        $mode = $this->option('passwords');
        if (! is_string($mode) || ! in_array($mode, self::MODES, true)) {
            return $this->refuse('--passwords must be default or random.');
        }
        try {
            $uid = ActorUid::from($this->option('actor-uid'));
        } catch (InvalidArgumentException $e) {
            return $this->refuse($e->getMessage());
        }
        $existing = DemoAccounts::organization();
        if ($existing instanceof Organization) {
            return $this->alreadySeeded($existing, $defaults);
        }

        $passwords = $mode === 'random' ? new RandomDemoPasswords : new DefaultDemoPassword;
        $this->laravel->instance(DemoPasswordSource::class, $passwords);
        $queue = config('queue.default');
        config(["queue.connections.{$queue}.after_commit" => true]); // jobs of a rolled-back seeding never run
        $organization = DB::transaction(function () use ($catalog, $audit, $uid, $mode): Organization {
            $catalog->handle();
            Model::unguarded(fn () => $this->laravel->make(DemoSeeder::class)->setContainer($this->laravel)->__invoke());
            $organization = DemoAccounts::organization() ?? throw new RuntimeException('The demo seeder created no demo organization.');
            $audit->cli('instance.demo_seeded', $organization, ['mode' => $mode, 'users' => count(DemoAccounts::emails())], $uid);

            return $organization;
        });

        return $this->seeded($organization, $mode, $passwords instanceof RandomDemoPasswords ? $passwords->issued() : []);
    }

    /** @param  array<string, string>  $issued  email => random password of the accounts the seeder created */
    private function seeded(Organization $organization, string $mode, array $issued): int
    {
        $data = [...$this->summary($organization), 'seeded' => true, 'passwords' => $mode, ...($mode === 'default' ? ['default_password' => DefaultDemoPassword::PASSWORD] : [])];
        if ($this->option('json') !== true) {
            $this->components->info("Demo organization [{$organization->name}] created. Sign in at {$data['login_url']}:");
            foreach (DemoAccounts::emails() as $email) {
                $this->line("  {$email}: ".($issued[$email] ?? ($mode === 'default' ? DefaultDemoPassword::PASSWORD : '(existing account, password unchanged)')));
            }

            return self::SUCCESS;
        }
        $result = JsonResult::ok(self::NAME, $data, changed: true);
        foreach ($issued as $email => $password) {
            $result = $result->withSecret(self::SECRET_PREFIX.$email, $password, null);
        }

        return $result->emit($this);
    }

    private function alreadySeeded(Organization $organization, DefaultPasswordUsers $defaults): int
    {
        $onDefault = $defaults->find(DemoAccounts::emails())['users'];
        $message = "The demo organization [{$organization->name}] exists already. Nothing was changed.";
        if ($this->option('json') !== true) {
            $this->components->warn($message);

            return self::SUCCESS;
        }
        $data = [...$this->summary($organization), 'seeded' => false, 'passwords' => null, 'default_password_users' => count($onDefault), 'deleted' => $organization->trashed()];

        return JsonResult::ok(self::NAME, $data)->withWarning('already_seeded', $message, 'Rotate the demo passwords with `fieldset demo --rotate-passwords`.')->emit($this);
    }

    /** @return array{organization: array{id: int, name: string, slug: string}, users: list<array{email: string, name: string, roles: list<string>}>, login_url: string} */
    private function summary(Organization $organization): array
    {
        return [
            'organization' => ['id' => (int) $organization->getKey(), 'name' => (string) $organization->name, 'slug' => (string) $organization->slug],
            'users' => DemoAccounts::personas(),
            'login_url' => url('/admin/login'),
        ];
    }

    private function refuse(string $message): int
    {
        if ($this->option('json') === true) {
            return JsonResult::failed(self::NAME, 'invalid_input', $message)->emit($this);
        }
        $this->components->error($message);

        return self::FAILURE;
    }
}
