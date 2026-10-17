<?php

declare(strict_types=1);

/*
 * Child process for OperationJournalTest: journals two phases of an upgrade, then kills itself with SIGKILL
 * between the start of a phase and its end, as a crashed supervisor would.
 * Usage: php journal-kill.php <instance directory>
 */

use App\Domain\Instance\Operations\OperationJournal;
use App\Domain\Instance\State\InstanceDirectory;

require __DIR__.'/../../../vendor/autoload.php';

$journal = new OperationJournal(InstanceDirectory::open($argv[1]));
$journal->begin('upgrade', 1000, ['target' => '2.0.1']);
$journal->startPhase('pull');
$journal->completePhase('pull');
$journal->startPhase('backup', ['safety_backup' => '/srv/fsa/backups/safety-1']);

posix_kill(getmypid(), SIGKILL);
