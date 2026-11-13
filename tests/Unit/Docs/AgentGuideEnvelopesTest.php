<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Support\Docs\GuideBlocks;
use Tests\Support\Instance\BackupSetFixture;
use Tests\Support\Instance\DemoFixtures;
use Tests\Support\Instance\InstanceCommandFixture;
use Tests\Support\Instance\RestoreFixture;
use Tests\Support\Instance\TemporaryInstance;

/*
 * The `json expect` blocks of docs/guide/agent-guide.md against the envelopes the commands return, in the fast suite
 * (plan U8, ADR 0096): each test runs the guide's block through the command's Action with scripted Docker and requires
 * GuideBlocks::mismatches() to be empty and the block's exit code. The real run of every block is J11AgentGuideTest.
 */

afterEach(fn () => TemporaryInstance::cleanup());

/** @return array{command: string, expect: array<string, mixed>, line: int} the guide's runnable block whose command contains the needle */
function guideBlock(string $needle): array
{
    $blocks = GuideBlocks::runnable((string) file_get_contents(base_path('docs/guide/agent-guide.md')));
    $found = array_values(array_filter($blocks, static fn (array $block): bool => str_contains($block['command'], $needle)));
    expect($found)->toHaveCount(1, "one guide block contains `{$needle}`");

    return $found[0];
}

/** @param  array<string, mixed>  $envelope */
function expectGuideEnvelope(array $block, int $code, array $envelope): void
{
    $fields = array_diff_key($block['expect'], ['exit_code' => true]);

    expect($code)->toBe($block['expect']['exit_code'], json_encode($envelope))
        ->and(GuideBlocks::mismatches($fields, $envelope))->toBe([], "guide line {$block['line']}");
}

it('block 01: install --demo --secrets-file returns the demo, the sign-in link in the file and the next commands', function (): void {
    $block = guideBlock('--demo --secrets-file ~/forms-secrets.json');
    $fixture = new InstanceCommandFixture;
    $fixture->oneOffs['app:demo-seed'] = DemoFixtures::seeded('default');
    $fixture->oneOffs['app:demo-passwords'] = DemoFixtures::rotated();
    $file = $fixture->directory->root.'/guide-secrets.json';

    [$code, $json] = $fixture->run('fieldset:install', InstanceCommandFixture::installFlags(['--org' => 'Example Ltd', '--owner-email' => 'owner@example.com', '--demo' => true, '--secrets-file' => $file]));

    expectGuideEnvelope($block, $code, $json);
    expect(array_keys($json['data']['demo']))->toContain('organization', 'users', 'login_url')
        ->and($json['data']['next_commands'])->not->toBe([])
        ->and($json['data']['sign_in_link']['delivered'])->toBe('secrets_file');
});

it('block 03: login-link --secrets-file delivers the link to the file', function (): void {
    $block = guideBlock('login-link owner@example.com --secrets-file');
    $fixture = new InstanceCommandFixture;
    $fixture->run('fieldset:install', InstanceCommandFixture::installFlags(['--owner-email' => 'owner@example.com']));

    [$code, $json] = $fixture->run('fieldset:login-link', ['email' => 'owner@example.com', '--secrets-file' => $fixture->directory->root.'/guide-link.json']);

    expectGuideEnvelope($block, $code, $json);
    expect($json['data']['sign_in_link']['delivered'])->toBe('secrets_file');
});

it('block 06: restore --drill reports the backup, the dropped scratch database and the storage', function (): void {
    $block = guideBlock('restore <backup> --drill');
    $restore = new RestoreFixture;
    $restore->backup();

    [$code, $json] = $restore->instance->run('fieldset:restore', ['backup' => 'manual-20261019T120000Z', '--drill' => true]);

    expectGuideEnvelope($block, $code, $json);
    expect($json['data']['storage'])->toBeArray()->not->toBe([]);
});

it('block 12: the shell line collects the encrypted backup, the identity and only the APP key lines', function (?string $previous): void {
    $block = guideBlock('mkdir -p -m 700 ~/forms-move');
    $home = TemporaryInstance::path();
    mkdir($home.'/forms/env', 0700, true);
    $app = "APP_NAME=Fieldset\nAPP_KEY='".BackupSetFixture::APP_KEY."'\n".($previous === null ? '' : "APP_PREVIOUS_KEYS='{$previous}'\n")."DB_PASSWORD=db-secret-value\nMAIL_PASSWORD=smtp-secret-value\n";
    file_put_contents($home.'/forms/env/app.env', $app);
    $set = BackupSetFixture::fieldset($home.'/forms/backups/manual-20261019T130000Z', extra: ['secrets.env' => "APP_KEY='".BackupSetFixture::APP_KEY."'\n"]);
    BackupSetFixture::encrypt($set);
    BackupSetFixture::identityFile($home.'/forms-identity.age');

    $process = Process::fromShellCommandline(str_replace('<backup-path>', $set, $block['command']), $home, ['HOME' => $home, 'PATH' => (string) getenv('PATH')]);
    $process->run();
    $keys = array_values(array_filter(explode("\n", (string) file_get_contents($home.'/forms-move/app-key.env'))));

    expect($process->getExitCode())->toBe($block['expect']['exit_code'], $process->getErrorOutput())
        ->and($keys)->toBe(array_values(array_filter(explode("\n", "APP_KEY='".BackupSetFixture::APP_KEY."'\n".($previous === null ? '' : "APP_PREVIOUS_KEYS='{$previous}'\n")))))
        ->and(file_get_contents($home.'/forms-move/app-key.env'))->not->toContain('DB_PASSWORD')->not->toContain('smtp-secret-value')
        ->and(file_get_contents($home.'/forms-move/backup.tar.age'))->toBe(file_get_contents($set.'/backup.tar.age'))
        ->and(file_get_contents($home.'/forms-move/identity.age'))->toBe(file_get_contents($home.'/forms-identity.age'))
        ->and(substr(sprintf('%o', fileperms($home.'/forms-move')), -3))->toBe('700')
        ->and(substr(sprintf('%o', fileperms($home.'/forms-move/app-key.env')), -3))->toBe('600');
})->with([
    'with a rotated key' => [BackupSetFixture::OTHER_KEY],
    'a key never rotated' => [null],
]);
