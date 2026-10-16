<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Notification preferences per user, event and channel (BUILD_PROMPT §8.9). User-level, not per
 * organization. Only explicit choices are stored; missing rows fall back to the defaults in
 * App\Domain\Notifications\Preferences\ChannelPreferences.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('event', 64);
            $table->string('channel', 16);
            $table->boolean('enabled');
            $table->timestamps();
            $table->unique(['user_id', 'event', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
