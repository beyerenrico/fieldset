<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The device's own submit time (M6, ADR 0035 "Backend", QUESTIONS #14): a submit queued offline reaches
 * the server later. Informational only; number, period and submitted_at stay on server time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('submissions', function (Blueprint $table): void {
            $table->timestamp('client_submitted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('submissions', function (Blueprint $table): void {
            $table->dropColumn('client_submitted_at');
        });
    }
};
