<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/* Retention scrubs a submission's notifications by `data->>'submission_uuid'` (ADR 0039, SubmissionTraces). */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE INDEX notifications_submission_uuid_index ON notifications ((data->>'submission_uuid')) WHERE (data->>'submission_uuid') IS NOT NULL");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS notifications_submission_uuid_index');
    }
};
