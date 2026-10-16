<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Saved reports (BUILD_PROMPT §8.4): form + filters + group-by + metric, optional scheduled email. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('form_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->jsonb('filters');
            $table->string('group_by', 80)->nullable();
            $table->string('metric', 8)->default('count');
            $table->string('metric_key', 64)->nullable();
            $table->string('visibility', 16)->default('private');
            $table->string('schedule', 16)->default('none');
            $table->jsonb('recipients');
            $table->timestamp('last_sent_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'visibility', 'created_by']);
            $table->index('schedule');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_reports');
    }
};
