<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * "Send to customer" requests (BUILD_PROMPT §9.2, M3-T08). A request made before the PDF exists waits
 * here and is mailed when the first rendition is created; afterwards it points at its mail_messages row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sends', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('submission_uuid');
            $table->foreign('submission_uuid')->references('uuid')->on('submissions')->cascadeOnDelete();
            $table->string('recipient_email', 254);
            $table->string('recipient_name')->nullable();
            $table->string('status', 32)->default('waiting_for_document');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('mail_message_id')->nullable()->constrained('mail_messages')->nullOnDelete();
            $table->unsignedBigInteger('rendition_id')->nullable(); // no FK: renditions are owned by Documents
            $table->text('error')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'submission_uuid']);
            $table->index(['submission_uuid', 'status']);
        });

        DB::statement("ALTER TABLE document_sends ADD CONSTRAINT document_sends_status_check CHECK (status IN ('waiting_for_document','queued','failed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sends');
    }
};
