<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forms', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('key', 64);
            $table->string('name');
            $table->string('category', 80)->nullable();
            $table->string('status', 20)->default('draft');
            $table->jsonb('draft_definition');
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->jsonb('import_report')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'key']);
            $table->index(['organization_id', 'status']);
        });

        Schema::create('form_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('form_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->jsonb('definition');
            $table->jsonb('data_json_schema');
            $table->char('checksum', 64);
            $table->unsignedBigInteger('design_version_id')->nullable();
            $table->text('changelog');
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at');
            $table->timestamp('retired_at')->nullable();
            $table->unique(['form_id', 'version']);
            $table->index(['organization_id', 'form_id']);
        });

        Schema::table('forms', function (Blueprint $table): void {
            $table->foreign('current_version_id')->references('id')->on('form_versions')->nullOnDelete();
        });

        Schema::create('form_access', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('form_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('role_name', 125)->nullable();
            $table->timestamps();
            $table->unique(['form_id', 'group_id', 'role_name']);
        });
        DB::statement('ALTER TABLE form_access ADD CONSTRAINT form_access_one_grantee CHECK ((group_id IS NULL) <> (role_name IS NULL))');

        // Published versions are immutable (BUILD_PROMPT §7.6): the only allowed update is retiring
        // (retired_at NULL -> value). Deletes need an explicit session flag (organization erasure).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION form_versions_immutable() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF current_setting('app.allow_immutable_delete', true) = 'on' THEN
                        RETURN OLD;
                    END IF;
                    RAISE EXCEPTION 'form_versions rows are immutable';
                END IF;
                IF OLD.retired_at IS NULL AND NEW.retired_at IS NOT NULL
                   AND (to_jsonb(NEW) - 'retired_at') = (to_jsonb(OLD) - 'retired_at') THEN
                    RETURN NEW;
                END IF;
                RAISE EXCEPTION 'form_versions rows are immutable (only retiring is allowed)';
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS form_versions_immutable_trg ON form_versions;
            CREATE TRIGGER form_versions_immutable_trg
                BEFORE UPDATE OR DELETE ON form_versions
                FOR EACH ROW EXECUTE FUNCTION form_versions_immutable();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS form_versions_immutable_trg ON form_versions; DROP FUNCTION IF EXISTS form_versions_immutable();');
        Schema::table('forms', fn (Blueprint $table) => $table->dropForeign(['current_version_id']));
        Schema::dropIfExists('form_access');
        Schema::dropIfExists('form_versions');
        Schema::dropIfExists('forms');
    }
};
