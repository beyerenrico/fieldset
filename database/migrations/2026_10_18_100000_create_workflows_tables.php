<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Workflow automation (BUILD_PROMPT §8.3, ADR 0032): definitions, runs with the definition snapshot they
 * executed, one row per step run, and outbound webhook endpoints. One run per (workflow, submission,
 * triggering event id) — NULLS NOT DISTINCT, so runs without a submission (schedule, form.published)
 * are deduplicated too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->string('trigger_type', 40);
            $table->jsonb('trigger_config')->default('{}');
            $table->jsonb('conditions')->nullable();
            $table->jsonb('steps')->default('[]');
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'trigger_type', 'active']);
        });

        Schema::create('webhook_endpoints', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('url', 2048);
            $table->text('secret');                                 // encrypted cast
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'name']);
        });

        Schema::create('workflow_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();
            $table->uuid('submission_uuid')->nullable();
            $table->foreign('submission_uuid')->references('uuid')->on('submissions')->cascadeOnDelete();
            $table->string('trigger', 40);
            $table->string('event_id', 100);
            $table->jsonb('definition_snapshot');
            $table->jsonb('context')->default('{}');
            $table->string('status', 16)->default('pending');
            $table->unsignedInteger('current_step')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->unique(['workflow_id', 'submission_uuid', 'event_id'])->nullsNotDistinct();
            $table->index(['organization_id', 'status']);
            $table->index(['submission_uuid', 'id']);
        });
        DB::statement("ALTER TABLE workflow_runs ADD CONSTRAINT workflow_runs_status_check CHECK (status IN ('pending','running','waiting','completed','failed','rejected'))");

        Schema::create('workflow_step_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_run_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('index');
            $table->string('type', 40);
            $table->string('status', 16)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->jsonb('log')->default('[]');
            $table->jsonb('output')->default('{}');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->unique(['workflow_run_id', 'index']);
        });
        DB::statement("ALTER TABLE workflow_step_runs ADD CONSTRAINT workflow_step_runs_status_check CHECK (status IN ('pending','running','waiting','completed','failed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_step_runs');
        Schema::dropIfExists('workflow_runs');
        Schema::dropIfExists('webhook_endpoints');
        Schema::dropIfExists('workflows');
    }
};
