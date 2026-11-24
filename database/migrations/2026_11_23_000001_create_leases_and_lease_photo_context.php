<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('leases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('lease_reference', 64);
            $table->string('tenant_name');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();

            $table->unique(['organisation_id', 'lease_reference']);
            $table->index(['organisation_id', 'property_id']);
        });

        Schema::table('evidence_links', function (Blueprint $table): void {
            $table->string('phase', 16)->nullable()->after('label');
            $table->foreignId('area_room_id')->nullable()->after('phase')->constrained('areas_rooms')->nullOnDelete();
            $table->timestamp('taken_at')->nullable()->after('area_room_id');
            $table
                ->foreignId('replaces_link_id')
                ->nullable()
                ->after('taken_at')
                ->constrained('evidence_links')
                ->nullOnDelete();
            $table->index(['attachable_type', 'attachable_id', 'phase']);
        });
    }

    public function down(): void
    {
        Schema::table('evidence_links', function (Blueprint $table): void {
            $table->dropIndex(['attachable_type', 'attachable_id', 'phase']);
            $table->dropForeign(['area_room_id']);
            $table->dropForeign(['replaces_link_id']);
            $table->dropColumn(['phase', 'area_room_id', 'taken_at', 'replaces_link_id']);
        });

        Schema::dropIfExists('leases');
    }
};
