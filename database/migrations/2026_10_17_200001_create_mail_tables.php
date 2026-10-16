<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Email delivery (BUILD_PROMPT §6.4, §8.8): per-organization mail settings, the delivery log and
 * expiring document download links.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('sender_name', 120)->nullable();
            $table->string('reply_to_address', 254)->nullable();
            $table->boolean('smtp_enabled')->default(false);
            $table->string('smtp_host')->nullable();
            $table->unsignedInteger('smtp_port')->nullable();
            $table->string('smtp_encryption', 16)->nullable();
            $table->text('smtp_username')->nullable();       // encrypted cast
            $table->text('smtp_password')->nullable();       // encrypted cast, never shown back
            $table->string('smtp_from_address', 254)->nullable();
            $table->timestamps();
        });

        Schema::create('mail_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('recipient_email', 254);
            $table->string('recipient_name')->nullable();
            $table->text('subject');
            $table->string('status', 16)->default('queued');
            $table->uuid('submission_uuid')->nullable();
            $table->foreign('submission_uuid')->references('uuid')->on('submissions')->nullOnDelete();
            $table->string('mailable_class');
            $table->string('message_id')->nullable();
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('payload');                          // encrypted serialized mailable, for retries
            $table->timestamp('queued_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status', 'created_at']);
            $table->index(['submission_uuid']);
        });

        Schema::create('document_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();         // sha256 of the URL token; the token is never stored
            $table->string('disk', 32);
            $table->string('path');
            $table->string('filename');
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            $table->uuid('submission_uuid')->nullable();
            $table->foreign('submission_uuid')->references('uuid')->on('submissions')->cascadeOnDelete();
            $table->unsignedBigInteger('rendition_id')->nullable(); // no FK: renditions are owned by Documents
            $table->timestamp('expires_at');
            $table->timestamp('downloaded_at')->nullable();
            $table->unsignedInteger('download_count')->default(0);
            $table->timestamps();
            $table->index(['organization_id', 'expires_at']);
            $table->index(['submission_uuid']);
        });

        DB::statement("ALTER TABLE mail_messages ADD CONSTRAINT mail_messages_status_check CHECK (status IN ('queued','sent','failed'))");
        DB::statement("ALTER TABLE mail_settings ADD CONSTRAINT mail_settings_encryption_check CHECK (smtp_encryption IS NULL OR smtp_encryption IN ('starttls','tls','none'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('document_links');
        Schema::dropIfExists('mail_messages');
        Schema::dropIfExists('mail_settings');
    }
};
