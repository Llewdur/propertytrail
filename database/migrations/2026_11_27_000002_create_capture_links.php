<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('capture_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lease_id')->constrained()->cascadeOnDelete();
            $table->string('phase', 16);
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['lease_id', 'revoked_at']);
        });

        Schema::table('evidence_links', function (Blueprint $table): void {
            $table->foreignId('capture_link_id')->nullable()->after('replaces_link_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('evidence_links', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('capture_link_id');
        });

        Schema::dropIfExists('capture_links');
    }
};
