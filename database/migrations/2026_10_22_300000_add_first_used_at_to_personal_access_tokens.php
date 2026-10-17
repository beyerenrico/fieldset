<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Integration tokens (ADR 0043): first_used_at makes the "first use" audit entry exactly-once (a conditional
 * update), and the organization index serves the admin token list and the per-organization revocation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->timestamp('first_used_at')->nullable()->after('abilities');
            $table->index(['organization_id', 'tokenable_type', 'tokenable_id'], 'personal_access_tokens_organization_owner_index');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropIndex('personal_access_tokens_organization_owner_index');
            $table->dropColumn('first_used_at');
        });
    }
};
