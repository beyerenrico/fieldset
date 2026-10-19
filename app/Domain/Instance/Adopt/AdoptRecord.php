<?php

declare(strict_types=1);

namespace App\Domain\Instance\Adopt;

use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Install\InstallRecord;
use App\Domain\Instance\Install\InstallRequest;
use App\Domain\Instance\Install\RecordDigest;
use App\Domain\Instance\Operations\Ledger;
use App\Domain\Instance\State\EnvFiles;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceState;
use Throwable;

/**
 * Adopt's `done` phase (plan U19, ADR 0087): the baseline snapshot of the running instance (after the smoke test, so its
 * test mail is not counted as a write) goes into `backups/ledger.log` as the `adopted` line with the 1.0 backup's chain
 * heads (epoch 1: adopt continues the 1.0 chains, it starts no epoch), and the state gets the `installed` entry (a
 * repeated install changes nothing) and the `adopted` entry (checkout, 1.0 project, backup) that uninstall reads.
 */
final readonly class AdoptRecord
{
    public function __construct(private InstanceDirectory $directory, private AdoptData $data, private Ledger $ledger) {}

    /**
     * @param  array<string, mixed>  $preflight
     * @param  array<string, mixed>  $data  the data phases' results
     * @return array{baseline: string, ledger: bool} where the baseline came from: `instance` (after the smoke test) or
     *                                               `copy` (the verified copy, when the instance could not be read)
     */
    public function done(InstanceState $state, ComposeProject $project, LegacyInstall $legacy, array $preflight, array $data, int $callerUid): array
    {
        $current = $this->directory->state();
        if (AdoptedInstance::record($current) !== null) {
            return ['baseline' => 'recorded', 'ledger' => true]; // a resumed done phase: recorded before it was killed
        }
        $backup = is_array($data['backup'] ?? null) ? $data['backup'] : [];
        try {
            [$baseline, $source] = [$this->data->snapshot($state, $project, $legacy)->toArray(), 'instance'];
        } catch (Throwable) {
            // uninstall then also counts the smoke test's writes and migrations' rows as written since adopt
            [$baseline, $source] = [is_array($data['verify']['snapshot'] ?? null) ? $data['verify']['snapshot'] : null, 'copy'];
        }
        $this->ledger->append(['action' => AdoptOperation::EVENT, 'host_uid' => $callerUid, 'checkout' => $legacy->checkout, 'project' => $legacy->project,
            'backup' => $backup['name'] ?? null, 'epoch' => 1, 'heads' => $backup['snapshot']['heads'] ?? [], 'baseline' => $baseline, 'baseline_source' => $source]);
        /** @var array<string, string|int|null> $context */
        $context = is_array($preflight['request'] ?? null) ? $preflight['request'] : [];
        $request = InstallRequest::fromOptions([])->over($context);
        $digest = RecordDigest::fromEnvFiles(new EnvFiles($this->directory));
        $this->directory->saveState($current
            ->withHistoryEntry(InstallRecord::EVENT, InstallRecord::entry($request, $current, $digest, false))
            ->withHistoryEntry(AdoptOperation::EVENT, ['checkout' => $legacy->checkout, 'project' => $legacy->project, 'version' => $legacy->version, 'backup' => is_string($backup['name'] ?? null) ? $backup['name'] : null]));

        return ['baseline' => $baseline === null ? 'none' : $source, 'ledger' => true];
    }
}
