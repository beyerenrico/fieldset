<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Renditions (BUILD_PROMPT §8.5): immutable PDFs of a submission with the form, design and renderer
 * versions they used. `input_hash` covers the canonical render input, `file_sha256` the stored file.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('renditions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();                      // public id for signed download links
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('submission_uuid');
            $table->foreign('submission_uuid')->references('uuid')->on('submissions')->cascadeOnDelete();
            $table->foreignId('form_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('design_version_id')->constrained()->restrictOnDelete();
            $table->char('input_hash', 64);
            $table->char('file_sha256', 64);
            $table->string('renderer_version', 32);
            $table->unsignedInteger('page_count');
            $table->string('disk', 32);
            $table->string('path');
            $table->unsignedBigInteger('size');
            $table->string('reason', 16);                        // submitted | rerender
            $table->unsignedBigInteger('created_by')->nullable(); // no FK: rows are immutable
            $table->timestamp('created_at');
            $table->index(['submission_uuid', 'id']);
            $table->index(['organization_id', 'created_at']);
        });

        // Renditions never change. Deletes need the explicit session flag (organization erasure, retention).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION renditions_immutable() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' AND current_setting('app.allow_immutable_delete', true) = 'on' THEN
                    RETURN OLD;
                END IF;
                RAISE EXCEPTION 'renditions rows are immutable';
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS renditions_immutable_trg ON renditions;
            CREATE TRIGGER renditions_immutable_trg
                BEFORE UPDATE OR DELETE ON renditions
                FOR EACH ROW EXECUTE FUNCTION renditions_immutable();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS renditions_immutable_trg ON renditions; DROP FUNCTION IF EXISTS renditions_immutable();');
        Schema::dropIfExists('renditions');
    }
};
