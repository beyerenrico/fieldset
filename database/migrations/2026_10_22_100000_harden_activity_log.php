<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit log hardening (BUILD_PROMPT §8.11, ADR 0038):
 * - Hash chain columns: entries are sealed after their transaction commits (chain_seq per organization,
 *   prev_hash, hash). Sealing is the only update the trigger allows, once, and only on these columns.
 * - A trigger refuses every other UPDATE, every DELETE and TRUNCATE. Retention pruning deletes through
 *   audit_prune(), which switches a transaction-local flag on for its own DELETE and off again; it never
 *   deletes entries younger than 30 days. Sealing goes through audit_seal().
 * - Indexes for the audit log UI filters (organization + time, event, actor) and the chain head lookup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table): void {
            $table->unsignedBigInteger('chain_seq')->nullable();
            $table->char('prev_hash', 64)->nullable();
            $table->char('hash', 64)->nullable();
            $table->index(['organization_id', 'created_at'], 'activity_log_org_created_idx');
            $table->index(['organization_id', 'event'], 'activity_log_org_event_idx');
            $table->index(['causer_type', 'causer_id'], 'activity_log_causer_idx');
            $table->index(['created_at'], 'activity_log_created_idx');
        });

        // One chain per organization; platform entries (no organization) share chain 0.
        DB::statement('CREATE UNIQUE INDEX activity_log_chain_idx ON activity_log (COALESCE(organization_id, 0), chain_seq) WHERE chain_seq IS NOT NULL');
        DB::statement('CREATE INDEX activity_log_unsealed_idx ON activity_log (id) WHERE hash IS NULL');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION activity_log_append_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF current_setting('app.audit_prune', true) = 'on' THEN
                        RETURN OLD;
                    END IF;
                    RAISE EXCEPTION 'activity_log is append-only: delete entries through audit_prune()';
                END IF;
                IF OLD.hash IS NULL AND NEW.hash IS NOT NULL
                   AND (to_jsonb(NEW) - 'hash' - 'prev_hash' - 'chain_seq') = (to_jsonb(OLD) - 'hash' - 'prev_hash' - 'chain_seq') THEN
                    RETURN NEW;
                END IF;
                RAISE EXCEPTION 'activity_log is append-only: entries cannot be changed';
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION activity_log_no_truncate() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'activity_log is append-only: TRUNCATE is not allowed';
            END;
            $$ LANGUAGE plpgsql;

            -- Entries older than the cutoff that pruning may delete. A chain is only ever cut at its start:
            -- a sealed entry stays while a later-created entry of its chain has a lower sequence number
            -- (sealing follows commit order, created_at follows insert order).
            CREATE OR REPLACE FUNCTION audit_prunable(cutoff timestamp)
            RETURNS TABLE (entry_id bigint, chain bigint, seq bigint, entry_hash char(64)) AS $$
                WITH kept AS (
                    SELECT COALESCE(k.organization_id, 0) AS chain, MIN(k.chain_seq) AS first_seq
                    FROM activity_log k
                    WHERE k.created_at >= cutoff AND k.chain_seq IS NOT NULL
                    GROUP BY 1
                )
                SELECT a.id, COALESCE(a.organization_id, 0), a.chain_seq, a.hash
                FROM activity_log a
                LEFT JOIN kept ON kept.chain = COALESCE(a.organization_id, 0)
                WHERE a.created_at < cutoff
                  AND (a.chain_seq IS NULL OR kept.first_seq IS NULL OR a.chain_seq < kept.first_seq)
            $$ LANGUAGE sql STABLE;

            -- Retention pruning: the only way to delete audit entries. Entries younger than 30 days are never
            -- deleted, whoever calls. Returns, per chain, the last deleted position and hash (the anchor
            -- audit:verify needs), so the caller records exactly what was cut in the same transaction.
            -- SECURITY DEFINER: a hardened install revokes UPDATE, DELETE, TRUNCATE on activity_log from the
            -- application role and grants EXECUTE on this function and audit_seal() only (ADR 0038).
            DROP FUNCTION IF EXISTS audit_prune(timestamp, integer);
            CREATE OR REPLACE FUNCTION audit_prune(cutoff timestamp)
            RETURNS TABLE (pruned_chain bigint, last_seq bigint, last_hash char(64), deleted bigint) AS $$
            BEGIN
                IF cutoff > (now() AT TIME ZONE 'UTC') - interval '30 days' THEN
                    RAISE EXCEPTION 'audit_prune: entries younger than 30 days are never deleted';
                END IF;
                PERFORM set_config('app.audit_prune', 'on', true);
                RETURN QUERY
                    WITH doomed AS (
                        DELETE FROM activity_log a USING audit_prunable(cutoff) p
                        WHERE a.id = p.entry_id
                        RETURNING COALESCE(a.organization_id, 0) AS c, a.chain_seq AS s, a.hash AS h
                    )
                    SELECT d.c, MAX(d.s), (ARRAY_AGG(d.h ORDER BY d.s DESC NULLS LAST))[1], COUNT(*)
                    FROM doomed d GROUP BY d.c;
                PERFORM set_config('app.audit_prune', 'off', true);
            END;
            $$ LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp;
            REVOKE ALL ON FUNCTION audit_prune(timestamp) FROM PUBLIC;

            -- Sealing: fills the chain columns of one unsealed entry (the only UPDATE the trigger allows).
            CREATE OR REPLACE FUNCTION audit_seal(entry bigint, seq bigint, prev char(64), entry_hash char(64)) RETURNS boolean AS $$
            BEGIN
                UPDATE activity_log SET chain_seq = seq, prev_hash = prev, hash = entry_hash WHERE id = entry AND hash IS NULL;
                RETURN FOUND;
            END;
            $$ LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp;
            REVOKE ALL ON FUNCTION audit_seal(bigint, bigint, char, char) FROM PUBLIC;
            REVOKE ALL ON FUNCTION audit_prunable(timestamp) FROM PUBLIC;

            DROP TRIGGER IF EXISTS activity_log_append_only_trg ON activity_log;
            CREATE TRIGGER activity_log_append_only_trg
                BEFORE UPDATE OR DELETE ON activity_log
                FOR EACH ROW EXECUTE FUNCTION activity_log_append_only();

            DROP TRIGGER IF EXISTS activity_log_no_truncate_trg ON activity_log;
            CREATE TRIGGER activity_log_no_truncate_trg
                BEFORE TRUNCATE ON activity_log
                FOR EACH STATEMENT EXECUTE FUNCTION activity_log_no_truncate();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS activity_log_no_truncate_trg ON activity_log;
            DROP TRIGGER IF EXISTS activity_log_append_only_trg ON activity_log;
            DROP FUNCTION IF EXISTS audit_seal(bigint, bigint, char, char);
            DROP FUNCTION IF EXISTS audit_prune(timestamp);
            DROP FUNCTION IF EXISTS audit_prunable(timestamp);
            DROP FUNCTION IF EXISTS activity_log_no_truncate();
            DROP FUNCTION IF EXISTS activity_log_append_only();
            DROP INDEX IF EXISTS activity_log_unsealed_idx;
            DROP INDEX IF EXISTS activity_log_chain_idx;
            SQL);
        Schema::table('activity_log', function (Blueprint $table): void {
            $table->dropIndex('activity_log_org_created_idx');
            $table->dropIndex('activity_log_org_event_idx');
            $table->dropIndex('activity_log_causer_idx');
            $table->dropIndex('activity_log_created_idx');
            $table->dropColumn(['chain_seq', 'prev_hash', 'hash']);
        });
    }
};
