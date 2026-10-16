<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M4 review hardening (ADR 0032 amendment):
 *  - `cancelled` for tasks, runs and step runs (a submission left review another way);
 *  - `submission_events.workflows_processed_at`: processing marker of the queued trigger job, so a lost
 *    or failed start is swept (the history columns stay append-only); existing rows count as processed;
 *  - `mail_messages.workflow_step_run_id`: mails queued by a `send_email` step, counted for the
 *    per-organization and per-submission caps.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE tasks DROP CONSTRAINT tasks_status_check');
        DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_status_check CHECK (status IN ('open','completed','cancelled'))");
        DB::statement('ALTER TABLE workflow_runs DROP CONSTRAINT workflow_runs_status_check');
        DB::statement("ALTER TABLE workflow_runs ADD CONSTRAINT workflow_runs_status_check CHECK (status IN ('pending','running','waiting','completed','failed','rejected','cancelled'))");
        DB::statement('ALTER TABLE workflow_step_runs DROP CONSTRAINT workflow_step_runs_status_check');
        DB::statement("ALTER TABLE workflow_step_runs ADD CONSTRAINT workflow_step_runs_status_check CHECK (status IN ('pending','running','waiting','completed','failed','cancelled'))");

        Schema::table('submission_events', function (Blueprint $table): void {
            $table->timestamp('workflows_processed_at')->nullable();
        });
        DB::statement('UPDATE submission_events SET workflows_processed_at = created_at');
        // Draft rows (no from_status) never fire SubmissionStatusChanged and are not swept.
        DB::statement('CREATE INDEX submission_events_unprocessed_idx ON submission_events (created_at) WHERE workflows_processed_at IS NULL AND from_status IS NOT NULL');

        Schema::table('mail_messages', function (Blueprint $table): void {
            $table->foreignId('workflow_step_run_id')->nullable()->constrained()->nullOnDelete();
        });
        DB::statement('CREATE INDEX mail_messages_workflow_idx ON mail_messages (organization_id, created_at) WHERE workflow_step_run_id IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS mail_messages_workflow_idx');
        Schema::table('mail_messages', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('workflow_step_run_id');
        });
        DB::statement('DROP INDEX IF EXISTS submission_events_unprocessed_idx');
        Schema::table('submission_events', function (Blueprint $table): void {
            $table->dropColumn('workflows_processed_at');
        });

        DB::statement("UPDATE workflow_step_runs SET status = 'completed' WHERE status = 'cancelled'");
        DB::statement('ALTER TABLE workflow_step_runs DROP CONSTRAINT workflow_step_runs_status_check');
        DB::statement("ALTER TABLE workflow_step_runs ADD CONSTRAINT workflow_step_runs_status_check CHECK (status IN ('pending','running','waiting','completed','failed'))");
        DB::statement("UPDATE workflow_runs SET status = 'rejected' WHERE status = 'cancelled'");
        DB::statement('ALTER TABLE workflow_runs DROP CONSTRAINT workflow_runs_status_check');
        DB::statement("ALTER TABLE workflow_runs ADD CONSTRAINT workflow_runs_status_check CHECK (status IN ('pending','running','waiting','completed','failed','rejected'))");
        DB::statement("UPDATE tasks SET status = 'completed' WHERE status = 'cancelled'");
        DB::statement('ALTER TABLE tasks DROP CONSTRAINT tasks_status_check');
        DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_status_check CHECK (status IN ('open','completed'))");
    }
};
