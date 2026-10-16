<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Document designs (BUILD_PROMPT §8.5): a mutable draft per design and immutable published versions,
 * versioned like forms (§7.6, §8.10). One default design per organization.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('designs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('key', 64);
            $table->string('name');
            $table->boolean('is_default')->default(false);
            $table->jsonb('settings');
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'key']);
        });
        DB::statement('CREATE UNIQUE INDEX designs_one_default_per_org ON designs (organization_id) WHERE is_default');

        Schema::create('design_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('design_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->jsonb('settings');
            $table->char('checksum', 64);
            $table->text('changelog');
            // No FK: a nullOnDelete action would fire the immutability trigger (see form_versions).
            $table->unsignedBigInteger('published_by')->nullable()->index();
            $table->timestamp('published_at');
            $table->timestamp('retired_at')->nullable();
            $table->unique(['design_id', 'version']);
            $table->index(['organization_id', 'design_id']);
        });

        Schema::table('designs', function (Blueprint $table): void {
            $table->foreign('current_version_id')->references('id')->on('design_versions')->nullOnDelete();
        });

        // Immutable like form_versions: the only allowed update is retiring (retired_at NULL -> value).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION design_versions_immutable() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF current_setting('app.allow_immutable_delete', true) = 'on' THEN
                        RETURN OLD;
                    END IF;
                    RAISE EXCEPTION 'design_versions rows are immutable';
                END IF;
                IF OLD.retired_at IS NULL AND NEW.retired_at IS NOT NULL
                   AND (to_jsonb(NEW) - 'retired_at') = (to_jsonb(OLD) - 'retired_at') THEN
                    RETURN NEW;
                END IF;
                RAISE EXCEPTION 'design_versions rows are immutable (only retiring is allowed)';
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS design_versions_immutable_trg ON design_versions;
            CREATE TRIGGER design_versions_immutable_trg
                BEFORE UPDATE OR DELETE ON design_versions
                FOR EACH ROW EXECUTE FUNCTION design_versions_immutable();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS design_versions_immutable_trg ON design_versions; DROP FUNCTION IF EXISTS design_versions_immutable();');
        Schema::table('designs', fn (Blueprint $table) => $table->dropForeign(['current_version_id']));
        Schema::dropIfExists('design_versions');
        Schema::dropIfExists('designs');
    }
};
