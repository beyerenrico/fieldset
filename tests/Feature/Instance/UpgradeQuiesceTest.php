<?php

declare(strict_types=1);

use App\Domain\Instance\Operations\OperationJournal;
use Tests\Support\Instance\TemporaryInstance;
use Tests\Support\Instance\UpgradeFixture;

/*
 * The upgrade until its safety backup (plan U18, KTD19 steps 2–5, R57, R69, ADR 0086, review U18 A1, A5, S1): requests
 * that passed before the gate rose finish before anything is sealed or backed up (a bounded wait on the database's
 * client sessions, exit 9 when they stay), the space check counts the live data when no backup exists yet, and a safety
 * backup an upgrade leaves behind on its way back is sealed like a restore's, also after a kill.
 */

beforeEach(function (): void {
    $this->upgrade = new UpgradeFixture;
    $this->dir = $this->upgrade->instance->directory->root;
});

afterEach(function (): void {
    TemporaryInstance::cleanup();
});

/** @return list<string> the one-offs of interest in the order they ran */
function quiesceOrder(UpgradeFixture $upgrade): array
{
    $order = [];
    foreach ($upgrade->instance->docker->called('composeRun') as $call) {
        $command = $call['command'];
        $order[] = match (true) {
            ($command[2] ?? null) === 'instance:upgrade-db' => 'db:'.($command[3] ?? ''),
            ($command[2] ?? null) === 'audit:seal' => 'seal',
            ($command[1] ?? null) === 'backup-daemon' && str_starts_with((string) ($command[3] ?? ''), '--kind=') => 'backup',
            default => null,
        };
    }

    return array_values(array_filter($order));
}

it('waits after the gate rose until no request holds a database session, and only then seals and backs up', function (): void {
    $this->upgrade->sessions = [[['pid' => 7, 'application_name' => '', 'user' => 'form_schema', 'state' => 'active']], []];

    [$code, $json] = $this->upgrade->upgrade();
    $order = quiesceOrder($this->upgrade);
    $foreign = array_keys($order, 'db:foreign', true);

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($foreign)->toHaveCount(2)
        ->and(max($foreign))->toBeLessThan(array_search('seal', $order, true))
        ->and(array_search('seal', $order, true))->toBeLessThan(array_search('backup', $order, true));
});

it('reverts with exit 9 and seals nothing when a session stays past the bound, naming it', function (): void {
    $this->upgrade->sessions = [[['pid' => 7, 'application_name' => 'psql', 'user' => 'form_schema', 'state' => 'idle in transaction']]];

    [$code, $json] = $this->upgrade->upgrade();

    expect($code)->toBe(9, (string) json_encode($json))
        ->and($json['errors'][0]['code'])->toBe('requests_in_flight')
        ->and($json['errors'][0]['message'])->toContain('psql')
        ->and(quiesceOrder($this->upgrade))->not->toContain('seal')->not->toContain('backup')
        ->and($this->upgrade->gate()['raised'])->toBeFalse()
        ->and(new OperationJournal($this->upgrade->instance->directory)->current())->toBeNull();
});

it('sizes the space check from the live database and storage volume when they outgrow the newest backup', function (): void {
    $this->upgrade->liveSize = ['database_bytes' => 20 * 1024 ** 3, 'storage_bytes' => 5 * 1024 ** 3];

    [$code, $json] = $this->upgrade->upgrade();

    expect($code)->toBe(3, (string) json_encode($json))
        ->and($json['errors'][0]['code'])->toBe('low_disk_space')
        ->and($json['data']['required_bytes'])->toBe(3 * 25 * 1024 ** 3 + 1024 ** 3)
        ->and($this->upgrade->gate()['raised'] ?? false)->toBeFalse();
});

it('seals the safety backup when the upgrade goes back after taking it (exit 9)', function (): void {
    $this->upgrade->encrypt();
    $this->upgrade->damagedSafety = true;

    [$code, $json] = $this->upgrade->upgrade();

    expect($code)->toBe(9, (string) json_encode($json))
        ->and($json['errors'][0]['code'])->toBe('safety_backup_unverified')
        ->and($this->upgrade->sealed())->toBe([$this->upgrade->safetyBackups[0]]);
});

it('journals the safety backup before verifying it, so a revert resumed after a kill seals it', function (bool $noted): void {
    $this->upgrade->damagedSafety = true;
    [$code] = $this->upgrade->upgrade();
    expect($code)->toBe(9)->and($this->upgrade->sealed())->toBe([]);
    $this->upgrade->reopen('upgrade', 'safety_backup', dropEvents: ! $noted); // killed while verifying (or before the note)
    $this->upgrade->encrypt();

    [$code, $json] = $this->upgrade->upgrade(['--resume' => true, '--to' => null]);

    expect($code)->toBe(9, (string) json_encode($json))
        ->and($json['errors'][0]['code'])->toBe('upgrade_interrupted')
        ->and($this->upgrade->sealed())->toBe([$this->upgrade->safetyBackups[0]]);
})->with(['noted' => true, 'killed before the note' => false]);
