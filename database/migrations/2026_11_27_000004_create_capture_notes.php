<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // What the tenant said about the property. Their own statement, not a verified finding.
        Schema::create('capture_room_ratings', function (Blueprint $table): void {
            $this->statementColumns($table);
            $table->string('condition', 16);

            $table->unique(['lease_id', 'phase', 'area_room_id']);
        });

        Schema::create('capture_issues', function (Blueprint $table): void {
            $this->statementColumns($table);
            // Set by the tenant's phone, so a retried note and its close-ups meet on one issue.
            $table->string('issue_key', 64);
            $table->string('kind', 16);
            $table->string('note', 500);

            $table->unique(['lease_id', 'phase', 'issue_key']);
        });

        Schema::table('evidence_links', function (Blueprint $table): void {
            $table->foreignId('capture_issue_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('evidence_links', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('capture_issue_id');
        });

        Schema::dropIfExists('capture_issues');
        Schema::dropIfExists('capture_room_ratings');
    }

    private function statementColumns(Blueprint $table): void
    {
        $table->id();
        $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
        $table->foreignId('lease_id')->constrained()->cascadeOnDelete();
        $table->string('phase', 16);
        $table->foreignId('area_room_id')->nullable()->constrained('areas_rooms')->nullOnDelete();
        $table->foreignId('capture_link_id')->nullable()->constrained()->nullOnDelete();
        $table->string('recorded_by');
        $table->timestamp('recorded_at');
        $table->timestamps();
    }
};
