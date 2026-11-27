<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('site_type', 32);
            $table->timestamps();

            $table->index(['organisation_id', 'name']);
        });

        Schema::table('properties', function (Blueprint $table): void {
            $table->foreignId('site_id')->nullable()->after('organisation_id')->constrained()->nullOnDelete();
            $table->string('owning_entity_name')->nullable();
            $table->string('owning_entity_registration_number', 64)->nullable();
        });

        // A shared or outdoor area belongs to a site instead of a unit.
        Schema::table('areas_rooms', function (Blueprint $table): void {
            $table->unsignedBigInteger('property_id')->nullable()->change();
            $table->foreignId('site_id')->nullable()->after('property_id')->constrained()->cascadeOnDelete();
        });

        Schema::create('lease_areas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lease_id')->constrained()->cascadeOnDelete();
            $table->foreignId('area_room_id')->constrained('areas_rooms')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['lease_id', 'area_room_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lease_areas');

        Schema::table('areas_rooms', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('site_id');
        });

        Schema::table('properties', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('site_id');
            $table->dropColumn(['owning_entity_name', 'owning_entity_registration_number']);
        });

        Schema::dropIfExists('sites');
    }
};
