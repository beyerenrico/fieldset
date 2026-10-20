<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Support\Docker\InstanceJourney;
use Tests\Support\Docker\SharedHarness;
use Tests\Support\Docker\TestImage;
use Tests\Support\Docs\GuideBlocks;
use Tests\Support\Docs\GuideCommand;

/*
 * J11 (BUILD_PROMPT_V2 §11, R51, plan U21): the agent guide's doc-test. Every `sh runnable` block of
 * docs/guide/agent-guide.md runs, in the guide's order, against the harness release (install.sh of the release, the
 * instance's shim, real Docker) and must exit with its `json expect` block's exit code and return its fields.
 * GuideCommand maps what the harness stands in for (the one-liner, `~/forms…` → `fsa-test-j11-forms…` in the work
 * directory, a stand-in Mailpit for the SMTP server, placeholders from earlier results). The test provides the two
 * files the guide asks the person to create (the SMTP password and the backup passphrase). Transcripts go to
 * docs/agent/milestones/evidence/$FSA_EVIDENCE_MILESTONE/J11-*.json; everything labelled with the instances' UUIDs, the
 * stand-in and the work directory are removed afterwards.
 */

const J11_GUIDE = 'docs/guide/agent-guide.md';

function j11Blocks(): array
{
    return GuideBlocks::runnable((string) file_get_contents(dirname(__DIR__, 3).'/'.J11_GUIDE));
}

/** @return array<string, array{0: int}> */
function j11Dataset(): array
{
    $cases = [];
    foreach (j11Blocks() as $index => $block) {
        $first = strtok($block['command'], "\n");
        $cases[sprintf('%02d line %d: %s', $index + 1, $block['line'], substr((string) $first, 0, 70))] = [$index];
    }

    return $cases;
}

function j11Docker(array $arguments): Process
{
    $process = new Process(['docker', ...$arguments]);
    $process->setTimeout(300);
    $process->run();

    return $process;
}

/** Starts the stand-in SMTP server (the instance's own Mailpit image) on the instance network, once. */
function j11SmtpHost(InstanceJourney $journey): string
{
    if (isset($GLOBALS['fsaJ11Smtp'])) {
        return $GLOBALS['fsaJ11Smtp'];
    }
    $state = json_decode((string) @file_get_contents($journey->work.'/fsa-test-j11-forms/state.json'), true);
    $name = 'fsa-test-j11-smtp-'.bin2hex(random_bytes(2));
    $GLOBALS['fsaJ11Smtp'] = $name;
    $GLOBALS['fsaJ11Project'] = $state['project'] ?? null;
    j11Docker(['run', '-d', '--name', $name, '--label', 'app.form-schema.test=j11', '-e', 'MP_SMTP_AUTH_ACCEPT_ANY=1', '-e', 'MP_SMTP_AUTH_ALLOW_INSECURE=1', (string) ($state['digests']['mailpit'] ?? 'missing-mailpit-digest')]);
    j11Docker(['network', 'connect', ($state['project'] ?? 'missing-project').'_default', $name]);

    return $name;
}

/** @return array<string, string> the `<name>` placeholders the guide text defines, from earlier results */
function j11Placeholders(): array
{
    $backup = $GLOBALS['fsaJ11Last']['backup']['data'] ?? [];

    return array_filter([
        '<backup>' => is_string($backup['backup'] ?? null) ? $backup['backup'] : null,
        '<backup-path>' => is_string($backup['path'] ?? null) ? $backup['path'] : null,
        '<version>' => $GLOBALS['fsaJ11Version'] ?? null,
        // The old host's `fsa version` (data.instance): the guide's upgrade runs `--to <version>`, the harness release.
        '<old-version>' => $GLOBALS['fsaJ11Version'] ?? null,
    ], is_string(...));
}

beforeAll(function (): void {
    if (TestImage::dockerUnavailableReason() !== null) {
        return;
    }

    try {
        [$harness, $release] = SharedHarness::get();
        $journey = new InstanceJourney($harness, $release, $harness->directory.'/j11');
        $GLOBALS['fsaJ11'] = $journey;
        $GLOBALS['fsaJ11Version'] = (string) $release->version;
        $GLOBALS['fsaJ11Last'] = [];
        $GLOBALS['fsaJ11Uuids'] = [];
        foreach (['smtp-password' => 'j11-smtp-'.bin2hex(random_bytes(8)), 'fsa-test-j11-forms-passphrase' => 'j11-'.bin2hex(random_bytes(12))] as $file => $value) {
            file_put_contents($journey->work.'/'.$file, $value."\n");
            chmod($journey->work.'/'.$file, 0600);
        }
    } catch (Throwable $exception) {
        $GLOBALS['fsaJ11Error'] = $exception;
    }
});

afterAll(function (): void {
    if (isset($GLOBALS['fsaJ11'])) {
        $work = $GLOBALS['fsaJ11']->work;
        if (isset($GLOBALS['fsaJ11Smtp'])) {
            j11Docker(['rm', '-f', '-v', $GLOBALS['fsaJ11Smtp']]);
        }
        foreach (['fsa-test-j11-forms', 'fsa-test-j11-forms-new'] as $dir) {
            InstanceJourney::cleanup($work.'/'.$dir, $GLOBALS['fsaJ11Uuids'][$dir] ?? null);
        }
        new Process(['rm', '-rf', $work])->run();
    }
    unset($GLOBALS['fsaJ11'], $GLOBALS['fsaJ11Error'], $GLOBALS['fsaJ11Project'], $GLOBALS['fsaJ11Version'], $GLOBALS['fsaJ11Last'], $GLOBALS['fsaJ11Uuids'], $GLOBALS['fsaJ11Smtp']);
});

beforeEach(function (): void {
    $reason = TestImage::dockerUnavailableReason();
    if ($reason !== null) {
        expect(getenv('FSA_REQUIRE_DOCKER'))->not->toBe('1', $reason);
        $this->markTestSkipped($reason.' (install journeys need a Docker engine)');
    }
    if (isset($GLOBALS['fsaJ11Error'])) {
        throw $GLOBALS['fsaJ11Error'];
    }
    $this->journey = $GLOBALS['fsaJ11'];
});

it('runs the agent guide block', function (int $index): void {
    $block = j11Blocks()[$index];
    $journey = $this->journey;
    $smtp = str_contains($block['command'], 'smtp.example.com') ? j11SmtpHost($journey) : null;
    $command = new GuideCommand($journey->release->directory.'/install.sh', $GLOBALS['fsaJ11Version'], $journey->work, $smtp, j11Placeholders())->prepare($block['command']);
    expect(GuideCommand::unresolved($command))->toBe([], "line {$block['line']}: a placeholder has no value yet");

    // The stand-in is no part of the instance: it leaves the instance network before an uninstall removes the network.
    if (str_contains($command, ' uninstall') && isset($GLOBALS['fsaJ11Smtp'])) {
        j11Docker(['network', 'disconnect', '-f', ($GLOBALS['fsaJ11Project'] ?? '').'_default', $GLOBALS['fsaJ11Smtp']]);
    }
    // `fsa upgrade` downloads the target's manifest inside its container: the release server's bridge address (as J7).
    $env = str_contains($command, ' upgrade') ? ['FSA_RELEASE_URL' => $journey->harness->containerReleaseUrl()] : [];

    $started = microtime(true);
    $process = $journey->shell($command, $env);
    $envelope = InstanceJourney::json($process);
    foreach (['fsa-test-j11-forms', 'fsa-test-j11-forms-new'] as $dir) {
        $GLOBALS['fsaJ11Uuids'][$dir] ??= InstanceJourney::uuid($journey->work.'/'.$dir);
    }
    if (is_string($envelope['command'] ?? null)) {
        $GLOBALS['fsaJ11Last'][$envelope['command']] = $envelope;
    }
    $journey->evidence(sprintf('J11-%02d-%s.json', $index + 1, is_string($envelope['command'] ?? null) ? $envelope['command'] : 'shell'), [
        'guide_line' => $block['line'], 'guide_command' => $block['command'], 'command' => $command, 'exit_code' => $process->getExitCode(),
        'seconds' => round(microtime(true) - $started, 1), 'stdout' => $envelope === [] ? $process->getOutput() : $envelope,
    ]);

    $expect = $block['expect'];
    $fields = array_diff_key($expect, ['exit_code' => true]);
    expect($process->getExitCode())->toBe($expect['exit_code'], "line {$block['line']}: {$command}\n".$process->getErrorOutput()."\n".$process->getOutput());
    if ($fields !== []) {
        expect(GuideBlocks::mismatches($fields, $envelope))->toBe([], "line {$block['line']}: ".$process->getOutput());
    }
})->with(j11Dataset());
