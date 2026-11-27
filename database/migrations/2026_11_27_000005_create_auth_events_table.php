<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Security record (standards docs/security-telemetry.md 4.1). No organisation: a failed sign-in has none.
        Schema::create('auth_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event', 32);
            $table->string('outcome', 16);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('occurred_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_events');
    }
};
