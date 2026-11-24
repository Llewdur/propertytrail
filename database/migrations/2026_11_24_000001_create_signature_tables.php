<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('inspection_signatures', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inspection_id')->constrained()->cascadeOnDelete();
            $table->string('party', 16);
            $table->string('signer_name');
            $table->longText('signature_image');
            $table->string('signed_via', 16);
            $table->foreignId('signed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('signer_ip', 45)->nullable();
            $table->timestamp('signed_at');
            $table->timestamps();

            $table->unique(['inspection_id', 'party']);
        });

        Schema::create('signing_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inspection_id')->constrained()->cascadeOnDelete();
            $table->string('party', 16);
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['inspection_id', 'party']);
        });

        Schema::create('signing_access_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('signing_token_id')->nullable()->constrained('signing_tokens')->nullOnDelete();
            $table->foreignId('organisation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('outcome', 16);
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signing_access_logs');
        Schema::dropIfExists('signing_tokens');
        Schema::dropIfExists('inspection_signatures');
    }
};
