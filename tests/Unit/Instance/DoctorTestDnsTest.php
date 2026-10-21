<?php

declare(strict_types=1);

use App\Domain\Instance\Doctor\DoctorProbes;
use App\Domain\Instance\State\InstanceState;
use Tests\Support\Docker\DistributionScripts;

/*
 * Plan U8 (ADR 0075): the release-harness journeys pin the doctor's DNS answers with FIELDSET_TEST_DNS, because the ops
 * container cannot use the harness DNS. Only harness images honour it; the shim passes it like the other FIELDSET_*
 * variables.
 */

afterEach(function (): void {
    putenv(DoctorProbes::TEST_DNS_ENV);
});

it('parses host=addresses entries, and drops the whole map for any malformed entry', function (string $value, array $map): void {
    expect(DoctorProbes::parseTestDns($value))->toBe($map);
})->with([
    'one host' => ['fsa-test-j02.test=192.168.1.20', ['fsa-test-j02.test' => ['192.168.1.20']]],
    'two hosts, IPv6' => ['A.test=10.0.0.1,fd00::1;b.test=8.8.8.8', ['a.test' => ['10.0.0.1', 'fd00::1'], 'b.test' => ['8.8.8.8']]],
    'trailing separator' => ['a.test=10.0.0.1;', ['a.test' => ['10.0.0.1']]],
    'not an address' => ['a.test=10.0.0.1;b.test=evil', []],
    'wildcard host' => ['*.test=10.0.0.1', []],
    'missing addresses' => ['a.test', []],
]);

it('ignores FIELDSET_TEST_DNS outside a release-harness build', function (): void {
    putenv(DoctorProbes::TEST_DNS_ENV.'=fsa-test-dns.invalid=192.0.2.10');

    expect((new DoctorProbes)->resolve('fsa-test-dns.invalid', DNS_A))->toBe([]);
});

it('answers from FIELDSET_TEST_DNS in a harness build, A and AAAA separately', function (): void {
    putenv(DoctorProbes::TEST_DNS_ENV.'=fsa-test-dns.invalid=192.0.2.10,2001:db8::10');
    $probes = new class extends DoctorProbes
    {
        protected function testDns(): array
        {
            return self::parseTestDns((string) getenv(self::TEST_DNS_ENV));
        }
    };

    expect($probes->resolve('FSA-TEST-DNS.invalid', DNS_A))->toBe(['192.0.2.10'])
        ->and($probes->resolve('fsa-test-dns.invalid', DNS_AAAA))->toBe(['2001:db8::10']);
});

it('passes FIELDSET_TEST_DNS from the shim into the ops container', function (): void {
    $scripts = new DistributionScripts;
    mkdir($scripts->root.'/home');
    $dir = $scripts->root.'/instance';
    mkdir($dir, 0700);
    $image = 'ghcr.io/beyerenrico/form-schema-app@sha256:'.str_repeat('1', 64);
    file_put_contents($dir.'/state.json', InstanceState::create('3f1c2b9e-8a4d-4c1e-9b7a-2d5e6f708192', $dir, 501, 20, 501)->withRelease('2.0.0', ['app' => $image])->toJson());
    copy(base_path('distribution/shim.sh'), $dir.'/fsa');
    chmod($dir.'/fsa', 0700);

    try {
        $process = $scripts->run($dir.'/fsa', ['doctor', '--json'], ['STUB_UNAME' => 'Linux', 'STUB_DOCKER_INFO' => 'Ubuntu 24.04 LTS|host|[]', 'FIELDSET_TEST_DNS' => 'a.test=10.0.0.1']);

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and(implode(' ', $scripts->opsRuns()[0]))->toContain('-e FIELDSET_TEST_DNS ')
            ->and($scripts->opsEnvironment())->toMatchArray(['FIELDSET_TEST_DNS' => 'a.test=10.0.0.1']);
    } finally {
        $scripts->cleanUp();
    }
});
