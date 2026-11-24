<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('inspections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lease_id')->nullable()->constrained()->nullOnDelete();
            $table->string('phase', 16);
            $table->string('status', 32)->default('draft');
            $table->text('overall_notes')->nullable();
            $table->foreignId('performed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->index(['organisation_id', 'property_id', 'status']);
        });

        Schema::create('inspection_sections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inspection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('area_room_id')->nullable()->constrained('areas_rooms')->nullOnDelete();
            $table->string('title');
            $table->string('room_type', 32)->default('living');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('saved_at')->nullable();
            $table->timestamps();

            $table->index(['inspection_id', 'sort_order']);
        });

        Schema::create('inspection_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inspection_section_id')->constrained('inspection_sections')->cascadeOnDelete();
            $table->string('label');
            $table->string('condition', 16)->nullable();
            $table->text('note')->nullable();
            $table->string('source', 16)->default('default');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['inspection_section_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspection_items');
        Schema::dropIfExists('inspection_sections');
        Schema::dropIfExists('inspections');
    }
};
