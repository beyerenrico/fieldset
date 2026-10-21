<?php

declare(strict_types=1);

namespace App\Domain\Platform\Instance\Reset;

use App\Domain\Audit\Chain\ChainEpoch;
use App\Domain\Audit\Chain\ChainHeads;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * The audit export `fieldset reset --no-backup` writes when real organizations exist (plan U23, R43, ADR 0088): the database
 * is recreated and no backup keeps it, so this is the record of what the audit log held. JSON Lines, streamed: a header
 * (`format`, `version`, `exported_at`, the epoch, every recorded epoch, the chain heads and the number of entries), then
 * every `activity_log` row as stored (properties were redacted when they were written; the chain columns let the heads
 * be checked against the rows). The writers are stopped and pending entries sealed before it runs, so header and rows
 * agree.
 */
final class AuditExport
{
    public const string FORMAT = 'fieldset-audit-export';

    public const string VERSION = '1.0';

    private const int CHUNK = 1000;

    private const int FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

    /**
     * @param  Closure(string): void  $write  receives one line at a time, newline included
     * @return int the entries written
     */
    public static function write(Closure $write): int
    {
        $write(json_encode([
            'format' => self::FORMAT, 'version' => self::VERSION, 'exported_at' => CarbonImmutable::now('UTC')->toIso8601ZuluString(),
            'epoch' => ChainEpoch::current(), 'epochs' => DB::table('audit_chain_epochs')->orderBy('epoch')->get()->all(),
            'heads' => (object) ChainHeads::read(), 'entries' => DB::table('activity_log')->count(),
        ], self::FLAGS)."\n");
        $written = 0;
        foreach (DB::table('activity_log')->orderBy('id')->lazyById(self::CHUNK) as $row) {
            $write(json_encode($row, self::FLAGS)."\n");
            $written++;
        }

        return $written;
    }
}
