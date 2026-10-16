<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Explorer search (ADR 0033: `value_text ILIKE '%term%'` within one form) without a full scan of the form's
 * values: a GIN trigram index (pg_trgm) on submission_values.value_text, asserted by
 * tests/Feature/Performance/SearchPlanTest.php. pg_trgm is a trusted extension (PostgreSQL ≥ 13): a user
 * with CREATE on the database may install it. Where that is not allowed, the migration logs a warning and
 * skips the index — search stays correct, only slower — instead of blocking the deployment. The index is
 * built CONCURRENTLY outside a transaction, so writes to submission_values continue while it builds (review L3).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const string INDEX = 'submission_values_value_text_trgm_idx';

    public function up(): void
    {
        try {
            DB::transaction(fn () => DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm')); // own transaction: a refusal keeps the connection usable
        } catch (Throwable $e) {
            Log::warning('pg_trgm could not be installed; explorer search runs without a trigram index.', ['error' => $e->getMessage()]);

            return;
        }

        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.self::INDEX.' ON submission_values USING gin (value_text gin_trgm_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::INDEX);
        // The extension stays installed: other objects may use it and dropping it needs its owner.
    }
};
