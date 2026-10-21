<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Support\JsonResult;
use App\Domain\Identity\LoginLinks\LoginLinks;
use App\Domain\Identity\Models\LoginLink;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * One-time sign-in links for `fieldset status` (ADR 0056, KTD25: a socket-less one-off). Never prints a token: only
 * the hash is stored.
 */
#[Description('List one-time sign-in links (with --unused only those that can still be used)')]
#[Signature('identity:login-links
        {--unused : Only links that are neither used nor expired}
        {--json : Print one JSON document on stdout}')]
final class LoginLinksCommand extends Command
{
    public function handle(LoginLinks $links): int
    {
        $rows = ($this->option('unused') === true ? $links->unused() : $links->recent())
            ->map(fn (LoginLink $link): array => $this->row($link))->values()->all();

        if ($this->option('json') === true) {
            return JsonResult::ok('identity:login-links', ['links' => $rows, 'count' => count($rows)])->emit($this);
        }
        if ($rows === []) {
            $this->components->info('No sign-in links.');

            return self::SUCCESS;
        }
        $this->table(
            ['ID', 'Email', 'Kind', 'State', 'Expires (UTC)', 'Used (UTC)', 'Used from', 'Host UID'],
            array_map(static fn (array $row): array => [
                $row['id'], $row['email'], $row['kind'], $row['state'], $row['expires_at'], $row['used_at'] ?? '–', $row['used_ip'] ?? '–', $row['created_by_uid'] ?? '–',
            ], $rows),
        );

        return self::SUCCESS;
    }

    /** @return array{id: int, email: string, kind: string, state: string, created_at: string|null, expires_at: string, used_at: string|null, used_ip: string|null, created_by: string, created_by_uid: int|null} */
    private function row(LoginLink $link): array
    {
        return [
            'id' => $link->id,
            'email' => $link->user->email,
            'kind' => $link->kind->value,
            'state' => LoginLinks::status($link),
            'created_at' => $this->time($link->created_at),
            'expires_at' => (string) $this->time($link->expires_at),
            'used_at' => $this->time($link->used_at),
            'used_ip' => $link->used_ip,
            'created_by' => $link->created_by,
            'created_by_uid' => $link->created_by_uid,
        ];
    }

    private function time(?Carbon $time): ?string
    {
        return $time?->toImmutable()->utc()->toIso8601ZuluString();
    }
}
