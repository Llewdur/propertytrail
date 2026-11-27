<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Encrypted, so a reminder can carry the tenant's own link; lookups still go by the hash.
        Schema::table('capture_links', static function (Blueprint $table): void {
            $table->text('token_encrypted')->nullable()->after('token_hash');
        });

        // One row per lease and phase: how the tenant is reminded (Fleet Push, or WhatsApp when they cannot accept).
        Schema::create('capture_reminders', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lease_id')->constrained()->cascadeOnDelete();
            $table->string('phase', 16);
            $table->string('push_link_id', 64)->nullable();
            $table->text('push_opt_in_url')->nullable();
            $table->timestamp('push_link_expires_at')->nullable();
            $table->timestamp('push_subscribed_at')->nullable();
            $table->string('push_device_class', 32)->nullable();
            $table->text('fallback_recipient')->nullable();
            $table->timestamp('fallback_at')->nullable();
            $table->unsignedInteger('reminders_sent')->default(0);
            $table->timestamp('last_reminded_at')->nullable();
            $table->timestamp('stopped_at')->nullable();
            $table->string('stop_reason', 32)->nullable();
            $table->timestamps();

            $table->unique(['lease_id', 'phase']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capture_reminders');

        Schema::table('capture_links', static function (Blueprint $table): void {
            $table->dropColumn('token_encrypted');
        });
    }
};
