<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Indexes for the dashboard widgets, the submission explorer and saved reports (BUILD_PROMPT §8.1 AC
 * "widget queries stay index-backed at 100k submissions per organization", §6.4, §8.4). Plans are asserted
 * by the EXPLAIN tests in tests/Feature/Performance and measured at 100k submissions in
 * docs/agent/milestones/evidence/M5/performance.md.
 *  - submissions (organization_id, submitted_at): KPI "submissions in range", chart per day, recent submissions;
 *  - submissions (organization_id, form_id, submitted_at): explorer rows of one form, newest first;
 *  - submissions (organization_id, updated_at) WHERE draft: stale drafts KPI;
 *  - tasks (completed_by): the "tasks I decided" branch of reviewer visibility (and the FK's ON DELETE);
 *  - submission_values (organization_id, form_id, key, submitted_at) replaces (…, key, status, submitted_at):
 *    nothing filters values by status, and with status in between, "values of a key, newest first" and
 *    "since a date" aggregates could not use submitted_at (220 ms → index walk that stops at the limit);
 *  - submission_values (submission_uuid, key) replaces (submission_uuid): the per-submission joins of saved
 *    reports and exports look up one key of one submission; deletes and the FK keep using the prefix.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX IF NOT EXISTS submissions_org_submitted_at_idx ON submissions (organization_id, submitted_at)');
        DB::statement('CREATE INDEX IF NOT EXISTS submissions_org_form_submitted_at_idx ON submissions (organization_id, form_id, submitted_at)');
        DB::statement("CREATE INDEX IF NOT EXISTS submissions_org_drafts_updated_at_idx ON submissions (organization_id, updated_at) WHERE status = 'draft'");
        DB::statement('CREATE INDEX IF NOT EXISTS tasks_completed_by_idx ON tasks (completed_by)');

        DB::statement('CREATE INDEX IF NOT EXISTS submission_values_org_form_key_submitted_at_idx ON submission_values (organization_id, form_id, key, submitted_at)');
        DB::statement('DROP INDEX IF EXISTS submission_values_organization_id_form_id_key_status_submitted_'); // Laravel's name, truncated to 63 bytes
        DB::statement('CREATE INDEX IF NOT EXISTS submission_values_submission_key_idx ON submission_values (submission_uuid, key)');
        DB::statement('DROP INDEX IF EXISTS submission_values_submission_uuid_index');
    }

    public function down(): void
    {
        DB::statement('CREATE INDEX IF NOT EXISTS submission_values_submission_uuid_index ON submission_values (submission_uuid)');
        DB::statement('DROP INDEX IF EXISTS submission_values_submission_key_idx');
        DB::statement('CREATE INDEX IF NOT EXISTS submission_values_organization_id_form_id_key_status_submitted_ ON submission_values (organization_id, form_id, key, status, submitted_at)');
        DB::statement('DROP INDEX IF EXISTS submission_values_org_form_key_submitted_at_idx');

        DB::statement('DROP INDEX IF EXISTS tasks_completed_by_idx');
        DB::statement('DROP INDEX IF EXISTS submissions_org_drafts_updated_at_idx');
        DB::statement('DROP INDEX IF EXISTS submissions_org_form_submitted_at_idx');
        DB::statement('DROP INDEX IF EXISTS submissions_org_submitted_at_idx');
    }
};
