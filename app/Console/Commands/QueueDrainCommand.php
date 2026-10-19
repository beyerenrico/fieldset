<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Support\JsonResult;
use App\Domain\Platform\Instance\Upgrade\QueueDrainer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * The queue sizes while an upgrade drains Horizon, and with `--drop` the export and deletion of the jobs left over
 * (`fsa upgrade --drop-jobs`; KTD19 step 4, plan U18, ADR 0086, QueueDrainer). Every Horizon queue unless `--queue`
 * names some.
 */
#[Description('Queue sizes for fsa upgrade\'s drain; --drop exports and deletes the remaining jobs')]
#[Signature('instance:queue-drain
        {--drop : Export every job left in the queues (stdout) and delete them}
        {--queue=* : Only these queues (default: every Horizon queue)}
        {--json : Print one JSON document on stdout}')]
final class QueueDrainCommand extends Command
{
    private const string NAME = 'instance:queue-drain';

    public function handle(QueueDrainer $drainer): int
    {
        $given = array_values(array_filter((array) $this->option('queue'), static fn (mixed $name): bool => is_string($name) && preg_match('/^[A-Za-z0-9_.:-]{1,100}$/D', $name) === 1));
        $names = $given === [] ? QueueDrainer::horizonQueues() : $given;

        try {
            $sizes = $drainer->sizes($names);
            $data = $this->option('drop') === true ? [...$sizes, ...$drainer->drop($names)] : $sizes;
        } catch (Throwable $e) {
            return $this->option('json') === true
                ? JsonResult::failed(self::NAME, 'queue_unreadable', $e->getMessage())->emit($this)
                : self::FAILURE;
        }
        if ($this->option('json') === true) {
            return JsonResult::ok(self::NAME, $data, changed: ($data['dropped'] ?? 0) > 0)->emit($this);
        }
        $this->line("pending {$sizes['pending']}, reserved {$sizes['reserved']}, delayed {$sizes['delayed']}");

        return self::SUCCESS;
    }
}
