<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The result of every backup run of a fieldset instance (R39, plan U16, ADR 0084): scheduled runs of the backup service,
 * manual `fieldset backup` and the safety backups of upgrade, restore, reset and rollback. The backup service writes it
 * through the role `fieldset_backup`, which may only read (pg_read_all_data) and insert here (`instance:backup-role`), so
 * the id comes from the database, not from a sequence the role would need rights on. `/platform` and the operator
 * alerts read it (App\Domain\Platform\Instance\BackupRuns); `fieldset status` reads the same runs from the ledger in
 * `backups/runs.log`, which also holds runs the database could not take (a failed run while Postgres is down).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(new Expression('gen_random_uuid()'));
            $table->string('kind', 40);
            $table->string('status', 16);
            $table->string('backup', 120)->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->boolean('encrypted')->default(false);
            $table->string('reason', 64)->nullable();
            $table->string('message', 500)->nullable();
            $table->unsignedBigInteger('free_bytes')->nullable();
            $table->unsignedInteger('missing_files')->default(0);
            $table->timestampTz('created_at')->useCurrent();
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_runs');
    }
};
