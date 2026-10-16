<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Delivery reliability (M3 review, ADR 0031):
 * - rendition_failures: the permanent render failure of a submission (one row per submission, cleared by
 *   the next successful render or a new submit), so the API, the inbox and waiting sends can react.
 * - renditions.request_id: dedupes a re-run of the same render job (killed worker, re-delivery).
 * - mail_messages status `sending`: claimed by a worker; a retry that finds it does not resend.
 * - document_sends.failure_reason: a fixed reason code shown as a translated text; an index for the
 *   per-organization send cap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rendition_failures', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('submission_uuid')->unique();
            $table->foreign('submission_uuid')->references('uuid')->on('submissions')->cascadeOnDelete();
            $table->string('reason', 32);
            $table->text('message')->nullable();               // scrubbed and truncated, never shown to users
            $table->unsignedInteger('attempts')->default(1);
            $table->timestamp('failed_at');
            $table->timestamps();
            $table->index(['organization_id', 'failed_at']);
        });
        DB::statement("ALTER TABLE rendition_failures ADD CONSTRAINT rendition_failures_reason_check CHECK (reason IN ('budget_exceeded','render_failed'))");

        Schema::table('renditions', function (Blueprint $table): void {
            $table->uuid('request_id')->nullable()->unique();
        });

        DB::statement('ALTER TABLE mail_messages DROP CONSTRAINT IF EXISTS mail_messages_status_check');
        DB::statement("ALTER TABLE mail_messages ADD CONSTRAINT mail_messages_status_check CHECK (status IN ('queued','sending','sent','failed'))");

        Schema::table('document_sends', function (Blueprint $table): void {
            $table->string('failure_reason', 32)->nullable();
            $table->index(['organization_id', 'created_at']); // per-organization send cap
        });
    }

    public function down(): void
    {
        Schema::table('document_sends', function (Blueprint $table): void {
            $table->dropIndex(['organization_id', 'created_at']);
            $table->dropColumn('failure_reason');
        });
        DB::statement("UPDATE mail_messages SET status = 'queued' WHERE status = 'sending'");
        DB::statement('ALTER TABLE mail_messages DROP CONSTRAINT IF EXISTS mail_messages_status_check');
        DB::statement("ALTER TABLE mail_messages ADD CONSTRAINT mail_messages_status_check CHECK (status IN ('queued','sent','failed'))");
        Schema::table('renditions', function (Blueprint $table): void {
            $table->dropUnique(['request_id']);
            $table->dropColumn('request_id');
        });
        Schema::dropIfExists('rendition_failures');
    }
};
