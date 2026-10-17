<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GDPR (BUILD_PROMPT §11 Privacy, ADR 0039): per-form retention, legal holds per form and per submission,
 * queued data exports (user data ZIP, audit log CSV), and the markers of anonymized submissions and erased
 * users.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retention_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('form_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('months')->nullable();        // null: keep indefinitely
            $table->string('action', 16)->default('anonymize');        // delete | anonymize
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_applied_at')->nullable();
            $table->timestamps();
        });
        DB::statement("ALTER TABLE retention_policies ADD CONSTRAINT retention_policies_action_check CHECK (action IN ('delete', 'anonymize'))");
        DB::statement('ALTER TABLE retention_policies ADD CONSTRAINT retention_policies_months_check CHECK (months IS NULL OR months > 0)');

        Schema::create('legal_holds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('form_id')->nullable()->constrained()->cascadeOnDelete();
            $table->uuid('submission_uuid')->nullable();
            $table->foreign('submission_uuid')->references('uuid')->on('submissions')->cascadeOnDelete();
            $table->text('reason');
            $table->foreignId('placed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'released_at']);
        });
        DB::statement('ALTER TABLE legal_holds ADD CONSTRAINT legal_holds_one_target CHECK ((form_id IS NULL) <> (submission_uuid IS NULL))');
        DB::statement('CREATE UNIQUE INDEX legal_holds_active_form_idx ON legal_holds (form_id) WHERE released_at IS NULL AND form_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX legal_holds_active_submission_idx ON legal_holds (submission_uuid) WHERE released_at IS NULL AND submission_uuid IS NOT NULL');

        Schema::create('data_exports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('subject_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 24);                                 // user_data | audit_log
            $table->string('status', 16)->default('pending');           // pending | running | completed | failed
            $table->jsonb('options');
            $table->string('disk', 32);
            $table->string('path')->nullable();
            $table->string('file_name')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->unsignedInteger('rows')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'requested_by', 'created_at']);
        });
        DB::statement("ALTER TABLE data_exports ADD CONSTRAINT data_exports_kind_check CHECK (kind IN ('user_data', 'audit_log'))");
        DB::statement("ALTER TABLE data_exports ADD CONSTRAINT data_exports_status_check CHECK (status IN ('pending', 'running', 'completed', 'failed'))");

        Schema::table('submissions', function (Blueprint $table): void {
            $table->timestamp('anonymized_at')->nullable();
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('erased_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('erased_at'));
        Schema::table('submissions', fn (Blueprint $table) => $table->dropColumn('anonymized_at'));
        Schema::dropIfExists('data_exports');
        Schema::dropIfExists('legal_holds');
        Schema::dropIfExists('retention_policies');
    }
};
