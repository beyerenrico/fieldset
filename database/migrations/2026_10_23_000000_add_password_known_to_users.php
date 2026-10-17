<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Whether the person knows a password for the account (ADR 0039). False for accounts created by SSO or by an
 * invitation until a password is set; "Delete my account" then confirms with the email address instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('password_known')->default(true);
        });

        // Backfill: SSO-linked accounts that never changed their password.
        DB::update(<<<'SQL'
            UPDATE users SET password_known = false
            WHERE EXISTS (SELECT 1 FROM external_identities e WHERE e.user_id = users.id)
              AND NOT EXISTS (SELECT 1 FROM activity_log a WHERE a.event = 'auth.password_changed'
                  AND a.subject_type = ? AND a.subject_id = users.id::text)
            SQL, [User::class]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('password_known');
        });
    }
};
