<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The backup service also records its own start in `backup_runs` (kind `service`, status `started`, or `failed` when its
 * configuration is unusable), with the schedule it runs: `/platform` needs both to notice a service that is not running
 * or never completes a backup (R39, ADR 0084 amendment).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_runs', function (Blueprint $table): void {
            $table->string('schedule', 16)->nullable();
            $table->string('time_zone', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('backup_runs', function (Blueprint $table): void {
            $table->dropColumn(['schedule', 'time_zone']);
        });
    }
};
