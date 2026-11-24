<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('inspection_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inspection_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->string('reference', 64);
            $table->string('storage_path');
            $table->string('sha256', 64);
            $table->unsignedBigInteger('size_bytes');
            $table->timestamp('locked_at');
            $table->timestamps();

            $table->unique(['inspection_id', 'version']);
        });

        Schema::create('report_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inspection_report_id')->constrained('inspection_reports')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('report_access_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('report_link_id')->nullable()->constrained('report_links')->nullOnDelete();
            $table->foreignId('organisation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('outcome', 16);
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_access_logs');
        Schema::dropIfExists('report_links');
        Schema::dropIfExists('inspection_reports');
    }
};
