<?php

declare(strict_types=1);

namespace App\Domain\Instance\Adopt;

use App\Domain\Instance\Install\InstallRecord;
use App\Domain\Instance\Install\InstallRequest;
use App\Domain\Instance\Install\RecordDigest;
use App\Domain\Instance\Operations\Ledger;
use App\Domain\Instance\State\EnvFiles;
use App\Domain\Instance\State\InstanceDirectory;

/**
 * Adopt's `done` phase (plan U19, ADR 0087): the ledger gets the `adopted` line (the 1.0 backup's chain heads, epoch 1:
 * adopt continues the 1.0 chains and starts no epoch, and the baseline snapshot the `baseline` phase took before 2.0
 * started, review A2), once per journal (a run killed after the append does not append again, review A7); the state gets
 * the `installed` entry (a repeated install changes nothing) and the `adopted` entry (checkout, 1.0 project, backup, jobs
 * left in 1.0's Valkey) that uninstall reads.
 */
final readonly class AdoptRecord
{
    public function __construct(private InstanceDirectory $directory, private Ledger $ledger) {}

    /**
     * @param  array<string, mixed>  $preflight
     * @param  array<string, mixed>  $data  the data phases' results (AdoptSequence::phaseData)
     * @return array{ledger: bool, recorded: bool} whether this run appended the ledger line and wrote the records
     */
    public function done(LegacyInstall $legacy, array $preflight, array $data, int $callerUid, string $journal): array
    {
        $backup = is_array($data['backup'] ?? null) ? $data['backup'] : [];
        $appended = ! array_any($this->ledger->entries(), static fn (array $entry): bool => ($entry['action'] ?? null) === AdoptOperation::EVENT && ($entry['journal'] ?? null) === $journal);
        if ($appended) {
            $this->ledger->append(['action' => AdoptOperation::EVENT, 'journal' => $journal, 'host_uid' => $callerUid, 'checkout' => $legacy->checkout, 'project' => $legacy->project,
                'backup' => $backup['name'] ?? null, 'epoch' => 1, 'heads' => $backup['snapshot']['heads'] ?? [], 'baseline' => $data['baseline']['snapshot'] ?? null,
                'legacy' => $legacy->toArray(), 'fence' => $data['fence'] ?? null]); // uninstall checks 1.0 against the fence (review V1)
        }
        $current = $this->directory->state();
        if (AdoptedInstance::record($current) !== null) {
            return ['ledger' => $appended, 'recorded' => false]; // a resumed done phase: recorded before it was killed
        }
        /** @var array<string, string|int|null> $context */
        $context = is_array($preflight['request'] ?? null) ? $preflight['request'] : [];
        $request = InstallRequest::fromOptions([])->over($context);
        $digest = RecordDigest::fromEnvFiles(new EnvFiles($this->directory));
        $quiesce = is_array($data['quiesce'] ?? null) ? $data['quiesce'] : [];
        $this->directory->saveState($current
            ->withHistoryEntry(InstallRecord::EVENT, InstallRecord::entry($request, $current, $digest, false))
            ->withHistoryEntry(AdoptOperation::EVENT, AdoptedInstance::entry($legacy, $backup, $quiesce)));

        return ['ledger' => $appended, 'recorded' => true];
    }
}
