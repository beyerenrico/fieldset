<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Customer send is idempotent by key on document_sends (M6, ADR 0035 "Backend"): stored idempotency
 * responses are pruned after 24 h, so without this a lost response replayed later would email the
 * customer twice. Sends without a key (admin inbox) stay unconstrained: Postgres treats NULLs as distinct.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_sends', function (Blueprint $table): void {
            $table->string('idempotency_key', 128)->nullable();
            $table->unique(['organization_id', 'submission_uuid', 'idempotency_key'], 'document_sends_idempotency_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('document_sends', function (Blueprint $table): void {
            $table->dropUnique('document_sends_idempotency_key_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};
