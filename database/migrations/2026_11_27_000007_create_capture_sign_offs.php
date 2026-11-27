<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // A tenant's signed approval of exactly what they captured. Never updated: a change means a new version.
        Schema::create('capture_sign_offs', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lease_id')->constrained()->cascadeOnDelete();
            $table->string('phase', 16);
            $table->unsignedInteger('version');
            $table->foreignId('capture_link_id')->nullable()->constrained()->nullOnDelete();
            $table->string('signer_name', 120);
            $table->longText('signature_image');
            $table->string('declaration_version', 32);
            $table->json('declarations');
            $table->string('content_hash', 64);
            $table->json('content');
            $table->text('comment')->nullable();
            $table->foreignId('capture_selfie_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('phone_unlocked');
            $table->string('signer_ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('signed_at');
            $table->timestamps();

            $table->unique(['lease_id', 'phase', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capture_sign_offs');
    }
};
