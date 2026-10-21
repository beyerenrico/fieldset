<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The demo organization is marked explicitly (R29, R65, ADR 0081 amendment 2026-10-19): only the demo seeder sets
 * `is_demo` (CreateOrganization with `demo: true`), and every decision about demo-ness reads it instead of the slug
 * `demo`, which a real organization could carry (`fieldset install --org Demo` before the slug was reserved). At most one
 * organization is the demo, also counting a deleted one (it still blocks a new seeding).
 *
 * Backfill: the organization with the slug `demo` is the demo only when the seeder's owner account (owner@demo.test,
 * DemoAccounts::OWNER) is its member; a real organization named "Demo" stays unmarked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->boolean('is_demo')->default(false);
        });
        DB::statement('CREATE UNIQUE INDEX organizations_single_demo ON organizations (is_demo) WHERE is_demo');
        $this->backfill();
    }

    public function backfill(): void
    {
        DB::update(<<<'SQL'
            UPDATE organizations SET is_demo = true
            WHERE slug = ? AND NOT EXISTS (SELECT 1 FROM organizations o WHERE o.is_demo)
              AND EXISTS (SELECT 1 FROM organization_user m JOIN users u ON u.id = m.user_id
                  WHERE m.organization_id = organizations.id AND u.email = ?)
            SQL, ['demo', 'owner@demo.test']);
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropIndex('organizations_single_demo');
            $table->dropColumn('is_demo');
        });
    }
};
