<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Support\ActorUid;
use App\Console\Support\JsonResult;
use App\Domain\Identity\Demo\DefaultPasswordUsers;
use App\Domain\Identity\Demo\DemoAccounts;
use App\Domain\Identity\Demo\RotateDemoPasswords;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * The app side of the demo password rules (R29, KTD25, ADR 0081), run by fsa as a socket-less one-off:
 *
 * - `--check`: which accounts (demo or not) still sign in with `password`. `fsa tunnel start` and the local-CA edge
 *   (U14) refuse while `data.count` is above 0. Read-only; JSON `data`: `checked` (accounts with a known password),
 *   `count`, `users` (list of `{email, demo}`).
 * - `--rotate`: `fsa demo --rotate-passwords`. Every demo account gets a new random password (printed once under
 *   `secrets` as `demo_password:<email>`), and its sessions, remember-me token and API tokens are revoked; audited
 *   per account. JSON `data`: `users` (emails), `sessions_ended`, `tokens_revoked`. No demo accounts: `changed: false`
 *   with the warning `no_demo_users`.
 */
#[Description('Check for accounts with the demo password, or rotate the demo users\' passwords (fsa demo)')]
#[Signature('app:demo-passwords
        {--check : Report the accounts that still sign in with the password "password"}
        {--rotate : Give every demo account a new random password and revoke its sessions and API tokens}
        {--actor-uid= : Host UID of the operator running fsa (audit)}
        {--json : Print one JSON document on stdout}')]
final class DemoPasswordsCommand extends Command
{
    private const string NAME = 'app:demo-passwords';

    public function handle(DefaultPasswordUsers $defaults, RotateDemoPasswords $rotate): int
    {
        try {
            $uid = ActorUid::from($this->option('actor-uid'));
            if ($this->option('check') === $this->option('rotate')) {
                throw new InvalidArgumentException('Pass exactly one of --check and --rotate.');
            }
        } catch (InvalidArgumentException $e) {
            return $this->refuse($e->getMessage());
        }

        return $this->option('check') === true ? $this->check($defaults) : $this->rotate($rotate, $uid);
    }

    private function check(DefaultPasswordUsers $defaults): int
    {
        $found = $defaults->find();
        $data = ['checked' => $found['checked'], 'count' => count($found['users']), 'users' => $found['users']];
        if ($this->option('json') === true) {
            return JsonResult::ok(self::NAME, $data)->emit($this);
        }
        $this->line($data['count'] === 0 ? "No account signs in with the demo password ({$data['checked']} checked)." : "{$data['count']} account(s) still sign in with the demo password:");
        foreach ($found['users'] as $user) {
            $this->line('  '.$user['email'].($user['demo'] ? ' (demo)' : ''));
        }

        return self::SUCCESS;
    }

    private function rotate(RotateDemoPasswords $rotate, ?int $uid): int
    {
        if (DemoAccounts::users()->isEmpty()) {
            $message = 'There are no demo accounts. Nothing was changed.';
            if ($this->option('json') === true) {
                return JsonResult::ok(self::NAME, ['users' => [], 'sessions_ended' => 0, 'tokens_revoked' => 0])->withWarning('no_demo_users', $message, 'Add the demo data with `fsa demo`.')->emit($this);
            }
            $this->components->warn($message);

            return self::SUCCESS;
        }
        $rotated = $rotate->handle($uid);
        if ($this->option('json') !== true) {
            $this->components->info('New demo passwords (shown once); their sessions and API tokens were revoked:');
            foreach ($rotated['passwords'] as $email => $password) {
                $this->line("  {$email}: {$password}");
            }

            return self::SUCCESS;
        }
        $result = JsonResult::ok(self::NAME, ['users' => array_keys($rotated['passwords']), 'sessions_ended' => $rotated['sessions_ended'], 'tokens_revoked' => $rotated['tokens_revoked']], changed: true);
        foreach ($rotated['passwords'] as $email => $password) {
            $result = $result->withSecret(DemoSeedCommand::SECRET_PREFIX.$email, $password, null);
        }

        return $result->emit($this);
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
