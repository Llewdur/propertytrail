<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Who appeared to be holding the phone. Kept apart from evidence, so it never reaches a report or room analysis.
        Schema::create('capture_selfies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lease_id')->constrained()->cascadeOnDelete();
            $table->string('phase', 16);
            $table->foreignId('capture_link_id')->nullable()->constrained()->nullOnDelete();
            $table->string('storage_path');
            $table->string('mime_type', 64);
            $table->unsignedInteger('file_size_bytes');
            $table->string('sha256_hash', 64);
            $table->timestamp('taken_at');
            $table->timestamps();

            $table->unique(['lease_id', 'phase', 'sha256_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('capture_selfies');
    }
};
