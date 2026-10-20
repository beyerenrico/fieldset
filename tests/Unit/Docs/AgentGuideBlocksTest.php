<?php

declare(strict_types=1);

use Tests\Support\Docs\GuideBlocks;
use Tests\Support\Docs\GuideCommand;

/*
 * The agent guide's runnable blocks (plan U21) in the fast suite: each has a valid expect block, and the doc-test's
 * mapping (J11) turns every block into a command without a placeholder left and with only fsa-test- instances.
 */

function agentGuide(): string
{
    return (string) file_get_contents(base_path('docs/guide/agent-guide.md'));
}

it('pairs every runnable block with an expect block that has an exit code', function (): void {
    $blocks = GuideBlocks::runnable(agentGuide());
    $commands = implode("\n", array_column($blocks, 'command'));

    expect(count($blocks))->toBeGreaterThanOrEqual(10)
        ->and($commands)->toContain('install.sh')->toContain('fsa backup')->toContain('fsa restore')->toContain('fsa upgrade')
        ->toContain('fsa config set mail smtp')->toContain('--from-backup')->toContain('fsa uninstall')
        ->and(array_filter($blocks, static fn (array $block): bool => ! str_contains($block['command'], '--json') && count($block['expect']) > 1))->toBe([]);
});

it('refuses a runnable block without an expect block or exit code', function (string $markdown, string $message): void {
    expect(fn () => GuideBlocks::runnable($markdown))->toThrow(RuntimeException::class, $message);
})->with([
    'no expect' => ["```sh runnable\nfsa status --json\n```\n\n```json\n{}\n```\n", 'not followed by'],
    'no exit code' => ["```sh runnable\nfsa status --json\n```\n```json expect\n{\"status\": \"ok\"}\n```\n", 'no integer'],
    'bad json' => ["```sh runnable\nx\n```\n```json expect\n{\n```\n", 'not valid JSON'],
    'unclosed' => ["```sh runnable\nx\n", 'Unclosed'],
]);

it('reads indented blocks inside list items', function (): void {
    $blocks = GuideBlocks::runnable("1. Step\n\n   ```sh runnable\n   fsa backup --json\n   ```\n\n   ```json expect\n   {\"exit_code\": 0}\n   ```\n");

    expect($blocks)->toBe([['command' => 'fsa backup --json', 'expect' => ['exit_code' => 0], 'line' => 3]]);
});

it('matches placeholders by type and other values exactly', function (): void {
    $envelope = ['status' => 'ok', 'data' => ['url' => 'http://localhost:8080', 'port' => 8080, 'users' => [['email' => 'a']], 'tunnel' => null, 'empty' => []]];

    expect(GuideBlocks::mismatches(['status' => 'ok', 'data' => ['url' => '<string>', 'port' => '<int>', 'users' => '<list>', 'tunnel' => '<any>', 'empty' => '<object>']], $envelope))->toBe([])
        ->and(GuideBlocks::mismatches(['status' => 'failed'], $envelope))->toBe(['status: expected "failed", got "ok"'])
        ->and(GuideBlocks::mismatches(['data' => ['port' => '<string>', 'mode' => 'local']], $envelope))->toBe(['data.port: expected <string>, got 8080', 'data.mode: missing'])
        ->and(GuideBlocks::mismatches(['data' => ['users' => '<object>']], $envelope))->toBe(['data.users: expected <object>, got [{"email":"a"}]'])
        ->and(GuideBlocks::mismatches(['data' => ['url' => ['x' => 1]]], $envelope))->toBe(['data.url: expected an object, got "http://localhost:8080"']);
});

it('keeps a block\'s own --version instead of adding the harness one (the move installs the old host\'s version)', function (): void {
    $command = new GuideCommand('/h/install.sh', '2.0.0-alpha.1-test', '/w', null, ['<old-version>' => '2.0.0-alpha.1-test']);

    expect($command->prepare('curl -fsSL https://x/releases/latest/download/install.sh | sh -s -- --version <old-version> --dir ~/forms-new --json'))
        ->toBe("sh '/h/install.sh' --version 2.0.0-alpha.1-test --dir /w/fsa-test-j11-forms-new --json")
        ->and($command->prepare('curl -fsSL https://x/releases/latest/download/install.sh | sh -s -- --dir ~/forms --json'))
        ->toBe("sh '/h/install.sh' --version '2.0.0-alpha.1-test' --dir /w/fsa-test-j11-forms --json");
});

it('maps every block to a harness command with fsa-test- instances and no placeholder left', function (): void {
    $command = new GuideCommand('/h/install.sh', '2.0.0-alpha.1-test', '/w', 'fsa-test-j11-smtp-ab', ['<backup>' => 'manual-1', '<backup-path>' => '/w/b', '<version>' => '2.0.0-alpha.1-test', '<old-version>' => '2.0.0-alpha.1-test']);
    $prepared = array_map(static fn (array $block): string => $command->prepare($block['command']), GuideBlocks::runnable(agentGuide()));
    $all = implode("\n", $prepared);

    expect(array_merge(...array_map(GuideCommand::unresolved(...), $prepared)))->toBe([])
        ->and($all)->not->toContain('~')->not->toContain('curl')->not->toContain('smtp.example.com')
        ->and($all)->toContain("sh '/h/install.sh' --version '2.0.0-alpha.1-test' --dir /w/fsa-test-j11-forms ")
        ->and($all)->toContain('--dir /w/fsa-test-j11-forms-new ')
        ->and($all)->toContain('--smtp-host fsa-test-j11-smtp-ab --smtp-port 1025 --smtp-encryption none')
        ->and(preg_match_all('~--dir (\S+)~', $all, $dirs))->toBeGreaterThan(0)
        ->and(array_filter($dirs[1], static fn (string $dir): bool => ! str_starts_with($dir, '/w/fsa-test-')))->toBe([]);
});
