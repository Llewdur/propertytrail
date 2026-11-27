<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // The tenant has no account, so the passkey binds to the lease and phase: one per capture.
        Schema::create('capture_passkeys', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lease_id')->constrained()->cascadeOnDelete();
            $table->string('phase', 16);
            $table->string('credential_id')->unique();
            $table->json('credential');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['lease_id', 'phase']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capture_passkeys');
    }
};
