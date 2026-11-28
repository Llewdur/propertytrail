<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('leases', function (Blueprint $table): void {
            $table
                ->foreignId('previous_lease_id')
                ->nullable()
                ->after('property_id')
                ->constrained('leases')
                ->nullOnDelete();
            $table->timestamp('moved_out_at')->nullable()->after('status');
        });

        // A staff-approved copy of an earlier tenancy's photo, shown to the next tenant.
        // The original evidence is never altered; the copy carries no metadata.
        Schema::create('shared_condition_photos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lease_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_lease_id')->nullable()->constrained('leases')->nullOnDelete();
            $table->foreignId('source_evidence_link_id')->nullable()->constrained('evidence_links')->nullOnDelete();
            $table->foreignId('area_room_id')->nullable()->constrained('areas_rooms')->nullOnDelete();
            $table->string('storage_path');
            $table->string('mime_type', 64);
            $table->char('sha256_hash', 64);
            $table->boolean('edited')->default(false);
            $table->string('description', 500)->nullable();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at');
            $table->timestamp('withdrawn_at')->nullable();
            $table->string('tenant_response', 16)->nullable();
            $table->string('tenant_response_note', 1000)->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->index(['lease_id', 'withdrawn_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shared_condition_photos');

        Schema::table('leases', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('previous_lease_id');
            $table->dropColumn('moved_out_at');
        });
    }
};
