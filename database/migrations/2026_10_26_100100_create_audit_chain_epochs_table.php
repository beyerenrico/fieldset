<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Audit chain epochs (plan KTD26, R68, ADR 0084 amending ADR 0038). Restore, rollback and reset put the database back to
 * an earlier point, so chain heads shipped outside the database (log shipping, backups' audit-heads.json) can be ahead of
 * the chains it holds. Each of them starts a new epoch, recorded here with the heads of the chains it replaced; heads
 * are compared within one epoch only. No row means epoch 1. Append-only, like activity_log (ADR 0038): a trigger refuses
 * UPDATE and DELETE.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_chain_epochs', function (Blueprint $table): void {
            $table->unsignedInteger('epoch')->primary();
            $table->timestampTz('started_at')->useCurrent();
            $table->string('reason', 32);
            $table->jsonb('previous_heads')->nullable();
        });
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_chain_epochs_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'audit_chain_epochs is append-only';
            END;
            $$ LANGUAGE plpgsql;
            DROP TRIGGER IF EXISTS audit_chain_epochs_append_only ON audit_chain_epochs;
            CREATE TRIGGER audit_chain_epochs_append_only BEFORE UPDATE OR DELETE ON audit_chain_epochs
                FOR EACH ROW EXECUTE FUNCTION audit_chain_epochs_append_only();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_chain_epochs');
        DB::unprepared('DROP FUNCTION IF EXISTS audit_chain_epochs_append_only()');
    }
};
