<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Filament's `exports` table (vendor/filament/actions create_exports_table) plus the columns of the
 * report exports (BUILD_PROMPT §8.4, ADR 0033): tenant, form, format, status, options (filters, selection,
 * locale, time zone), stored file and expiry. Model: App\Domain\Reports\Models\ReportExport.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('form_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('format', 16);
            $table->string('status', 16)->default('pending');
            $table->timestamp('completed_at')->nullable();
            $table->string('file_disk');
            $table->string('file_name')->nullable();
            $table->string('file_path')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('exporter');
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('total_rows');
            $table->unsignedInteger('successful_rows')->default(0);
            $table->jsonb('options');
            $table->text('error')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'user_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exports');
    }
};
