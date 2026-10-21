<?php

declare(strict_types=1);

use App\Domain\Instance\Backup\BackupManifest;
use App\Domain\Instance\Backup\KeyFingerprint;
use App\Domain\Instance\Compose\LocalCaSite;
use App\Domain\Instance\Compose\ServiceEnvironments;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\Restore\RestoreNames;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceState;
use App\Domain\Instance\Upgrade\ChildMigration;
use App\Domain\Platform\Instance\BackupRole;
use App\Domain\Platform\Instance\Reset\AuditExport;
use App\Domain\Platform\Instance\Restore\DatabaseAdmin;
use App\Domain\Platform\Instance\Restore\SecurityExport;
use App\Domain\Platform\Instance\Upgrade\UpgradeSessions;

/*
 * Names an instance persists (plan U4 of the Fieldset rename, R5): backups, keys, certificates, Docker labels and
 * Postgres roles carry them, so they cannot change after a release without a migration. Spelled out here on purpose:
 * a change to one of the constants fails this test instead of silently breaking existing backups.
 */

it('pins the persisted names to the Fieldset spelling', function (): void {
    expect(BackupManifest::FORMAT)->toBe('fieldset-backup')
        ->and(ServiceEnvironments::BACKUP_HMAC_INFO)->toBe('fieldset backup hmac v1')
        ->and(KeyFingerprint::INFO)->toBe('fieldset app key fingerprint v1')
        ->and(LocalCaSite::CA_NAME)->toBe('Fieldset local CA')
        ->and(AuditExport::FORMAT)->toBe('fieldset-audit-export')
        ->and(SecurityExport::FORMAT)->toBe('fieldset-security-state')
        ->and(BackupRole::NAME)->toBe('fieldset_backup')
        ->and(InstanceDirectory::MARKER)->toBe('.fieldset-instance')
        ->and(Labels::INSTANCE)->toBe('app.fieldset.instance')
        ->and(Labels::ROLE)->toBe('app.fieldset.role')
        ->and(ChildMigration::applicationName('0123456789abcdef'))->toBe('fieldset_upgrade_0123456789abcdef');
});

it('keeps every name of the longest instance within the 63 bytes of a Postgres identifier', function (): void {
    $uuid = '3f1c2b9e-8a4d-4c1e-9b7a-2d5e6f708192';
    $slug = InstanceState::slugFor('/srv/'.str_repeat('a', 120));
    $project = InstanceState::projectFor($slug, $uuid);
    $state = InstanceState::create($uuid, '/srv/'.str_repeat('a', 120), 1000, 1000, 0)->withSetting('db_database', str_repeat('d', 63));
    $names = RestoreNames::for($state, str_repeat('f', 16));
    $longestRole = 'fieldset_restore_'.str_repeat('a', 40);
    $longestApplication = 'fieldset_upgrade_'.str_repeat('f', 46);

    expect($project)->toStartWith('fieldset-'.$slug.'-')
        ->and(strlen($project))->toBeLessThanOrEqual(63)
        ->and(strlen($names->role))->toBeLessThanOrEqual(63)->and($names->role)->toStartWith('fieldset_restore_')
        ->and(strlen($names->scratch))->toBeLessThanOrEqual(63)
        ->and(strlen($names->aside))->toBeLessThanOrEqual(63)
        ->and(preg_match(DatabaseAdmin::ROLE, $longestRole))->toBe(1)->and(strlen($longestRole))->toBeLessThanOrEqual(63)
        ->and(preg_match(DatabaseAdmin::ROLE, $longestRole.'a'))->toBe(0)
        ->and(preg_match(UpgradeSessions::APPLICATION, $longestApplication))->toBe(1)->and(strlen($longestApplication))->toBe(63)
        ->and(preg_match(UpgradeSessions::APPLICATION, $longestApplication.'f'))->toBe(0)
        ->and(strlen(ChildMigration::applicationName(str_repeat('f', 16))))->toBeLessThanOrEqual(63);
});
