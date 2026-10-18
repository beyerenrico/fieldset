<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * One-time sign-in links (BUILD_PROMPT_V2 R27/R28, ADR 0056, ADR 0068): the owner's first link (a password-reset
 * broker token, 60 minutes) and `fsa login-link` links for existing admins (15 minutes). Only a SHA-256 hash of
 * the token is stored. Not tenant-owned, and no other table points here: a restore clears stale links with a
 * plain `DELETE FROM login_links` (R64).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('kind', 16);
            $table->char('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();
            $table->string('used_ip', 45)->nullable();
            $table->string('created_by', 16);
            $table->unsignedInteger('created_by_uid')->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_links');
    }
};
