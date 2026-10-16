<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saved reports pinned to a user's dashboard (BUILD_PROMPT §8.1 v1.0, §8.4, ADR 0034). One row per user
 * and report; deleting the report, the user or the organization removes the pin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_pins', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('saved_report_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            // Also the lookup index of a user's pins in an organization (organization_id, user_id).
            $table->unique(['organization_id', 'user_id', 'saved_report_id']);
            $table->index('saved_report_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_pins');
    }
};
