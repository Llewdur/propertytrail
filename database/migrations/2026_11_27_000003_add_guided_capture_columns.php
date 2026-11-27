<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('areas_rooms', function (Blueprint $table): void {
            // Set by the tenant's phone, so photos queued offline all land in the one room.
            $table->string('capture_key', 64)->nullable();
            // The room the tenant walked straight from into this one.
            $table->foreignId('adjoins_area_room_id')->nullable()->constrained('areas_rooms')->nullOnDelete();
            $table->unique(['property_id', 'capture_key']);
        });

        Schema::table('evidence_links', function (Blueprint $table): void {
            $table->unsignedSmallInteger('capture_corner')->nullable();
            $table->string('capture_shot', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('evidence_links', function (Blueprint $table): void {
            $table->dropColumn(['capture_corner', 'capture_shot']);
        });

        Schema::table('areas_rooms', function (Blueprint $table): void {
            $table->dropUnique(['property_id', 'capture_key']);
            $table->dropConstrainedForeignId('adjoins_area_room_id');
            $table->dropColumn('capture_key');
        });
    }
};
