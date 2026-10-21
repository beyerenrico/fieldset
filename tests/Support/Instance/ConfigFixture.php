<?php

declare(strict_types=1);

namespace Tests\Support\Instance;

use App\Domain\Instance\Config\ConfigKeys;
use App\Domain\Instance\Config\ConfigRequest;
use App\Domain\Instance\Docker\ContainerSpec;
use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\HostFacts;
use App\Domain\Instance\Operations\JournalRecord;
use App\Domain\Instance\Operations\OperationJournal;
use App\Domain\Instance\State\InstanceState;
use Closure;

/**
 * An installed instance (local mode, scripted Docker) for the `fieldset config` tests (plan U11, ADR 0077), with a run
 * responder that answers like a healthy host: port probes (loopback and host-wide) free unless listed, the smoke
 * test, the edge's HTTPS once it has a certificate and its HTTP → HTTPS redirect, and the config URL check. Tests
 * flip `$certificate`, `$smokeFails` or add ports to make a step fail.
 */
final class ConfigFixture
{
    public const string DOMAIN = 'forms.example.com';

    public const string HOST_ADDRESS = '8.8.4.4';

    public InstanceCommandFixture $instance;

    /** Whether the edge serves a certificate (HTTPS check). */
    public bool $certificate = true;

    /** HTTPS checks to fail before the edge serves a certificate (each attempt of the certificate wait counts). */
    public int $certificateFailures = 0;

    /** An ACME directory that refuses certificates: HTTPS fails while the rendered Caddyfile names it. */
    public ?string $refuseCa = null;

    /** Smoke-test curls to fail: a closure deciding per call (URL list, curl arguments), or null for none. */
    public ?Closure $smokeFails = null;

    /** @var list<int> ports taken on 127.0.0.1 */
    public array $takenLoopback = [];

    /** @var list<int> ports taken on 0.0.0.0 */
    public array $takenHost = [];

    /** @var list<string> curl URLs in call order */
    public array $curls = [];

    /**
     * @param  array<string, mixed>  $installFlags
     * @param  (Closure(InstanceCommandFixture): void)|null  $prepare  runs before the install, e.g. a proxy network
     */
    public function __construct(array $installFlags = [], ?Closure $prepare = null)
    {
        $this->instance = new InstanceCommandFixture;
        $this->instance->probes->dns[self::DOMAIN] = ['a' => [self::HOST_ADDRESS], 'aaaa' => []];
        $this->instance->facts(['lanAddresses' => [self::HOST_ADDRESS]]);
        $this->instance->docker->respondToRun(fn (ContainerSpec $spec): ?ProcessOutcome => $this->answer($spec));
        if ($prepare instanceof Closure) {
            $prepare($this->instance);
        }
        [$code, $json] = $this->instance->run('fieldset:install', InstanceCommandFixture::installFlags($installFlags));
        if ($code !== 0) {
            throw new \RuntimeException('The fixture install failed: '.json_encode($json));
        }
    }

    /**
     * `fieldset config set <key> <value>` with --json.
     *
     * @param  array<string, mixed>  $options
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function set(string $key, string $value, array $options = []): array
    {
        return $this->instance->run('fieldset:config', ['action' => 'set', 'key' => $key, 'value' => $value, ...$options]);
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    public function show(): array
    {
        return $this->instance->run('fieldset:config', ['action' => 'show']);
    }

    public function state(): InstanceState
    {
        return $this->instance->state();
    }

    /** A self-signed CA certificate, as a private ACME directory's HTTPS root would be. */
    public static function pem(): string
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $certificate = openssl_csr_sign(openssl_csr_new(['commonName' => 'Config Test Root '.bin2hex(random_bytes(3))], $key), null, $key, 30);
        openssl_x509_export($certificate, $pem);

        return $pem;
    }

    /** Index into the gateway's call log, to look only at what a later command did. */
    public function mark(): int
    {
        return count($this->instance->docker->calls);
    }

    /** @return list<string> */
    public function methodsSince(int $mark): array
    {
        return $this->instance->docker->methodsSince($mark);
    }

    /** @return list<array<string, mixed>> calls of a method since the mark */
    public function callsSince(int $mark, string $method): array
    {
        $calls = array_slice($this->instance->docker->calls, $mark);

        return array_values(array_map(static fn (array $call): array => $call['args'], array_filter($calls, static fn (array $call): bool => $call['method'] === $method)));
    }

    public function openJournal(): ?JournalRecord
    {
        return new OperationJournal($this->instance->directory)->current();
    }

    /**
     * Archived journals of an operation. Archive names start with the start second, so journals of one second have no
     * order among themselves: tests look for the one they need.
     *
     * @return list<JournalRecord>
     */
    public function archivedJournals(string $operation = 'config'): array
    {
        return array_values(array_filter(new OperationJournal($this->instance->directory)->history(), static fn (JournalRecord $record): bool => $record->operation() === $operation));
    }

    /** The only archived journal of the operation (fails the test when there are several). */
    public function archivedJournal(string $operation = 'config'): JournalRecord
    {
        $records = $this->archivedJournals($operation);
        if (count($records) !== 1) {
            throw new \RuntimeException(count($records)." archived {$operation} journals, expected one.");
        }

        return $records[0];
    }

    /** @return list<string> phase names (with repeats) of a journal */
    public static function phases(JournalRecord $record): array
    {
        return array_map(static fn (array $phase): string => $phase['name'].':'.$phase['status'], $record->phases());
    }

    /**
     * Leaves an open journal as a `config set` killed (SIGKILL) during apply would: snapshot and preflight completed,
     * apply started, the target state saved. `$refine` adjusts the target the preflight recorded.
     *
     * @param  (Closure(InstanceState): InstanceState)|null  $refine
     */
    public function interruptedDuringApply(ConfigRequest $request, ?Closure $refine = null): InstanceState
    {
        $before = $this->state();
        $applier = app(ConfigKeys::class)->applier($request->key);
        $change = $applier->preflight($applier->plan($before, $request), app(HostFacts::class));
        $after = $refine instanceof Closure ? $refine($change->after) : $change->after;
        $journal = new OperationJournal($this->instance->directory);
        $journal->begin('config', posix_getuid(), $request->toContext());
        $journal->startPhase('snapshot', [], false);
        $journal->completePhase('snapshot', [...$change->summary(), 'previous' => $before->toArray(), 'ca_root' => null]);
        $journal->startPhase('preflight', [], false);
        $journal->completePhase('preflight', ['pulled' => [], 'to' => $change->to, 'after' => $after->toArray(), 'warnings' => []]);
        $journal->startPhase('apply');
        $this->instance->directory->saveState($after);

        return $after;
    }

    private function answer(ContainerSpec $spec): ?ProcessOutcome
    {
        if ($spec->entrypoint === 'true') {
            preg_match('/^(.*):(\d+):\d+$/D', $spec->ports[0] ?? '', $binding);
            [, $ip, $port] = $binding + [null, '', '0'];
            $taken = in_array($ip, ['0.0.0.0', '[::]'], true) ? $this->takenHost : $this->takenLoopback;

            return in_array((int) $port, $taken, true)
                ? new ProcessOutcome(125, '', "docker: Error response from daemon: Bind for {$ip}:{$port} failed: port is already allocated.")
                : new ProcessOutcome(0);
        }
        if ($spec->entrypoint !== 'curl') {
            return null;
        }
        if (InstanceCommandFixture::catcherLookup($spec) instanceof ProcessOutcome) {
            return InstanceCommandFixture::catcherLookup($spec); // the install smoke test's test mail (InstallSmoke)
        }
        $urls = array_values(array_filter($spec->command, static fn (string $arg): bool => str_starts_with($arg, 'http://') || str_starts_with($arg, 'https://')));
        $this->curls = [...$this->curls, ...$urls];
        $last = $urls[array_key_last($urls)] ?? '';

        return match (true) {
            str_starts_with($last, 'https://') => $this->certificate && ! $this->caRefused() && $this->certificateFailures-- <= 0 ? new ProcessOutcome(0, '200') : new ProcessOutcome(35, '000', 'curl: (35) TLS connect error'),
            str_starts_with($last, 'http://') && ! str_starts_with($last, 'http://app:') && ! str_starts_with($last, 'http://gotenberg:') => new ProcessOutcome(0, '308 '.str_replace('http://', 'https://', $last)),
            $this->smokeFails instanceof Closure && ($this->smokeFails)($urls, $spec->command) => new ProcessOutcome(22, '', 'curl: (22) The requested URL returned error: 503'),
            default => new ProcessOutcome(0, "200 http://app:8080/up\n"),
        };
    }

    private function caRefused(): bool
    {
        $caddyfile = $this->instance->directory->read('edge/Caddyfile');

        return $this->refuseCa !== null && is_string($caddyfile) && str_contains($caddyfile, $this->refuseCa);
    }
}
