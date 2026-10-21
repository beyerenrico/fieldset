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
 * The queue sizes while an upgrade drains Horizon, and for `fieldset upgrade --drop-jobs` the export (`--export`) and then
 * the deletion (`--drop`) of the jobs still pending or reserved (KTD19 step 4, plan U18, ADR 0086, QueueDrainer). Every
 * Horizon queue unless `--queue` names some.
 */
#[Description('Queue sizes for fieldset upgrade\'s drain; --export prints and --drop deletes the jobs left over')]
#[Signature('instance:queue-drain
        {--export : Print the raw payloads of every job still pending or reserved (nothing is deleted)}
        {--drop : Delete every job still pending or reserved (after --export was saved); delayed jobs stay}
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
            $data = match (true) {
                $this->option('export') === true => [...$sizes, 'payloads' => $drainer->export($names)],
                $this->option('drop') === true => [...$sizes, 'dropped' => $drainer->drop($names)],
                default => $sizes,
            };
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
