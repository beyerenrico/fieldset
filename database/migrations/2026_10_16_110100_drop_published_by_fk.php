<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * form_versions are immutable (trigger). A nullOnDelete FK on published_by would make the trigger
 * fire when a user row is deleted; keep the historic publisher id without referential action.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('form_versions', function (Blueprint $table): void {
            $table->dropForeign(['published_by']);
            $table->index('published_by');
        });
    }

    public function down(): void
    {
        Schema::table('form_versions', function (Blueprint $table): void {
            $table->dropIndex(['published_by']);
            $table->foreign('published_by')->references('id')->on('users')->nullOnDelete();
        });
    }
};
