<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submissions', function (Blueprint $table): void {
            $table->uuid('uuid')->primary();                 // client-generated (UUIDv7), idempotent upserts
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('form_id')->constrained()->cascadeOnDelete();
            $table->foreignId('form_version_id')->constrained()->restrictOnDelete();
            $table->string('status', 24)->default('draft');
            $table->jsonb('data');
            $table->unsignedInteger('revision')->default(1);
            $table->string('number', 64)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->jsonb('device')->nullable();
            $table->jsonb('geo')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'form_id', 'status']);
            $table->index(['organization_id', 'created_by', 'updated_at']);
            $table->unique(['organization_id', 'form_id', 'number']);
        });

        Schema::create('submission_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('submission_uuid');
            $table->foreign('submission_uuid')->references('uuid')->on('submissions')->cascadeOnDelete();
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('comment')->nullable();
            $table->timestamp('created_at');
            $table->index(['submission_uuid', 'id']);
        });

        Schema::create('attachments', function (Blueprint $table): void {
            $table->uuid('uuid')->primary();                 // client-generated, idempotent upload
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('submission_uuid');
            $table->foreign('submission_uuid')->references('uuid')->on('submissions')->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('element_key', 64);
            $table->string('original_name')->nullable();
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64);
            $table->string('disk', 32);
            $table->string('path');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['submission_uuid']);
        });

        Schema::create('number_sequences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('form_id')->constrained()->cascadeOnDelete();
            $table->string('period', 16);
            $table->unsignedBigInteger('next_value')->default(1);
            $table->timestamps();
            $table->unique(['organization_id', 'form_id', 'period']);
        });

        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('key', 128);
            $table->char('request_hash', 64);
            $table->unsignedSmallInteger('status_code');
            $table->jsonb('response');
            $table->timestamp('created_at');
            $table->unique(['user_id', 'key']);
        });

        Schema::create('devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('device_uuid');
            $table->string('secret_hash');
            $table->string('name', 120)->nullable();
            $table->string('platform', 60)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('wipe_requested_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'device_uuid']);
        });

        Schema::table('groups', function (Blueprint $table): void {
            // Members of a sharing group see each other's submissions in the Field App (§8.6, §9.3).
            $table->boolean('share_submissions')->default(false)->after('description');
        });

        DB::statement("ALTER TABLE submissions ADD CONSTRAINT submissions_status_check CHECK (status IN ('draft','submitted','in_review','changes_requested','approved','completed','void'))");
    }

    public function down(): void
    {
        Schema::table('groups', fn (Blueprint $table) => $table->dropColumn('share_submissions'));
        Schema::dropIfExists('devices');
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('number_sequences');
        Schema::dropIfExists('attachments');
        Schema::dropIfExists('submission_events');
        Schema::dropIfExists('submissions');
    }
};
