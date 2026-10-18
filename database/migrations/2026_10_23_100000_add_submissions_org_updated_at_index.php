<?php

declare(strict_types=1);

use App\Support\Database\ConcurrentIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * submissions (organization_id, updated_at) for the API submission list (GET /api/v1/submissions, M8-T05):
 * newest change first, 50 per page, for the Field App and integration tokens. Without it every page sorts
 * all of the organization's submissions (100k: 23 ms for page 1, 113 ms and a 110 MB on-disk sort for page
 * 100); with it the page is read backwards from the index and stops at the limit. The partial drafts index
 * of the stale-drafts KPI stays (it is smaller and asserted by the EXPLAIN tests).
 * Built CONCURRENTLY outside a transaction, like the dashboard indexes (review L3).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        ConcurrentIndex::create('submissions_org_updated_at_idx', 'ON submissions (organization_id, updated_at)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS submissions_org_updated_at_idx');
    }
};
