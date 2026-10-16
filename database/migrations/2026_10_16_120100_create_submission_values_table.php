<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Read model for reporting (BUILD_PROMPT §8.4): one row per submitted value, repeater rows with path. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submission_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('form_id')->constrained()->cascadeOnDelete();
            $table->foreignId('form_version_id')->constrained()->restrictOnDelete();
            $table->uuid('submission_uuid');
            $table->foreign('submission_uuid')->references('uuid')->on('submissions')->cascadeOnDelete();
            $table->string('status', 24);
            $table->timestamp('submitted_at')->nullable();
            $table->string('key', 64);
            $table->string('path', 255);
            $table->string('role', 64)->nullable();
            $table->text('value_text')->nullable();
            $table->decimal('value_num', 38, 10)->nullable();
            $table->date('value_date')->nullable();
            $table->index(['organization_id', 'form_id', 'key', 'value_num']);
            $table->index(['organization_id', 'form_id', 'key', 'value_date']);
            $table->index(['organization_id', 'form_id', 'key', 'status', 'submitted_at']);
            $table->index('submission_uuid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_values');
    }
};
