<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Human steps (BUILD_PROMPT §6.4, §8.3): review tasks created by a workflow `request_review` step,
 * assigned to a user or a group, with an SLA due date. One task per step run (a retried step never
 * creates a second task).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20)->default('review');
            $table->uuid('submission_uuid');
            $table->foreign('submission_uuid')->references('uuid')->on('submissions')->cascadeOnDelete();
            $table->foreignId('workflow_run_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('workflow_step_run_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('assignee_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assignee_group_id')->nullable()->constrained('groups')->nullOnDelete();
            $table->timestamp('due_at')->nullable();
            $table->string('status', 16)->default('open');
            $table->string('outcome', 16)->nullable();
            $table->text('comment')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status', 'due_at']);
            $table->index(['submission_uuid', 'status']);
            $table->index(['assignee_user_id', 'status']);
            $table->index(['assignee_group_id', 'status']);
        });
        DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_status_check CHECK (status IN ('open','completed'))");
        DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_outcome_check CHECK (outcome IS NULL OR outcome IN ('approved','rejected'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
