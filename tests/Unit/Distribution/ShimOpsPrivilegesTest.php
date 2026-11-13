<?php

declare(strict_types=1);

use App\Domain\Instance\State\InstanceState;
use Tests\Support\Docker\DistributionScripts;

/*
 * Every container the shim starts drops all capabilities and cannot gain privileges (ADR 0097, defense in depth: the
 * mounted Docker socket still makes the ops container root-equivalent on the host). The ops container as the host user
 * keeps nothing; as 0:0 it keeps the capabilities fieldset_caps_ops_root names; the measuring and the leftover-fix
 * containers keep the one or two their code path needs.
 */

const PRIVILEGES_IMAGE = 'ghcr.io/beyerenrico/fieldset@sha256:1111111111111111111111111111111111111111111111111111111111111111';

beforeEach(function (): void {
    $this->scripts = new DistributionScripts;
    mkdir($this->scripts->root.'/home');
    $this->dir = $this->scripts->root.'/instance';
    mkdir($this->dir, 0700);
    $state = InstanceState::create('3f1c2b9e-8a4d-4c1e-9b7a-2d5e6f708192', $this->dir, posix_getuid(), posix_getgid(), posix_getuid())
        ->withRelease('2.1.1', ['app' => PRIVILEGES_IMAGE]);
    file_put_contents($this->dir.'/state.json', $state->toJson());
    copy(base_path('distribution/shim.sh'), $this->dir.'/fieldset');
    chmod($this->dir.'/fieldset', 0700);
    $this->env = ['STUB_UNAME' => 'Linux', 'STUB_DOCKER_INFO' => 'Ubuntu 24.04 LTS|host|[]'];
});

afterEach(fn () => $this->scripts->cleanUp());

/** @param  list<string>  $run  @return list<string> every value after $flag */
function privilegeValues(array $run, string $flag): array
{
    $values = [];
    foreach ($run as $index => $arg) {
        if ($arg === $flag) {
            $values[] = $run[$index + 1];
        }
    }

    return $values;
}

it('runs the ops container as the host user with no capability and no new privileges', function (): void {
    $process = $this->scripts->run($this->dir.'/fieldset', ['doctor', '--json'], [...$this->env, 'STUB_MEASURE_OWNER' => (string) posix_getuid()]);
    $run = $this->scripts->opsRuns()[0];

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(privilegeValues($run, '--user'))->toBe([posix_getuid().':'.posix_getgid()])
        ->and(privilegeValues($run, '--cap-drop'))->toBe(['ALL'])
        ->and(privilegeValues($run, '--security-opt'))->toBe(['no-new-privileges'])
        ->and(privilegeValues($run, '--cap-add'))->toBe([]);
});

it('runs the ops container as 0:0 with exactly the capabilities of its code paths', function (): void {
    $process = $this->scripts->run($this->dir.'/fieldset', ['doctor', '--json'], [...$this->env, 'STUB_MEASURE_OWNER' => '0']);
    $run = $this->scripts->opsRuns()[0];

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(privilegeValues($run, '--user'))->toBe(['0:0'])
        ->and(privilegeValues($run, '--cap-drop'))->toBe(['ALL'])
        ->and(privilegeValues($run, '--security-opt'))->toBe(['no-new-privileges'])
        ->and(privilegeValues($run, '--cap-add'))->toBe(['CHOWN', 'DAC_OVERRIDE', 'FOWNER']);
});

it('measures with root that can only traverse a directory of another owner', function (): void {
    $this->scripts->run($this->dir.'/fieldset', ['doctor', '--json'], [...$this->env, 'STUB_MEASURE_OWNER' => '0']);
    $run = $this->scripts->measureRuns()[0];

    expect(privilegeValues($run, '--user'))->toBe(['0:0'])
        ->and(privilegeValues($run, '--cap-drop'))->toBe(['ALL'])
        ->and(privilegeValues($run, '--security-opt'))->toBe(['no-new-privileges'])
        ->and(privilegeValues($run, '--cap-add'))->toBe(['DAC_READ_SEARCH']);
});

it('fixes leftovers with the capabilities find and chown need', function (): void {
    $process = $this->scripts->source(base_path('distribution/shim.sh'), 'fieldset_harden "$fieldset_caps_reclaim"; printf %s "$fieldset_hardening"');

    expect($process->getOutput())->toBe('--cap-drop ALL --security-opt no-new-privileges --cap-add CHOWN --cap-add DAC_READ_SEARCH');
});
