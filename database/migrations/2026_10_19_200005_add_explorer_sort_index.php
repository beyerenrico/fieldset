<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The explorer's default order `submitted_at desc, uuid desc` within one form (ExplorerQuery::defaultOrder)
 * as an index walk that stops at the page size: (organization_id, form_id, submitted_at, uuid) replaces
 * (organization_id, form_id, submitted_at), whose prefix it keeps. Built CONCURRENTLY outside a
 * transaction so writes to submissions are not blocked while it builds (review L3).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS submissions_org_form_submitted_uuid_idx ON submissions (organization_id, form_id, submitted_at, uuid)');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS submissions_org_form_submitted_at_idx');
    }

    public function down(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS submissions_org_form_submitted_at_idx ON submissions (organization_id, form_id, submitted_at)');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS submissions_org_form_submitted_uuid_idx');
    }
};
