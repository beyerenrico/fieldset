<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency keys are looked up per organization, so the unique index includes the organization.
 * The response is stored as the raw body (jsonb would reorder keys) and pruned by age.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('idempotency_keys', function (Blueprint $table): void {
            $table->dropUnique(['user_id', 'key']);
            $table->unique(['organization_id', 'user_id', 'key']);
            $table->index('created_at');
        });

        DB::statement('ALTER TABLE idempotency_keys ALTER COLUMN response TYPE text USING response::text');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE idempotency_keys ALTER COLUMN response TYPE jsonb USING response::jsonb');

        Schema::table('idempotency_keys', function (Blueprint $table): void {
            $table->dropIndex(['created_at']);
            $table->dropUnique(['organization_id', 'user_id', 'key']);
            $table->unique(['user_id', 'key']);
        });
    }
};
