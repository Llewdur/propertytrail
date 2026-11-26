<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('organisation_invitations', function (Blueprint $table): void {
            $table->dropUnique(['organisation_id', 'email']);
            $table->index(['organisation_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::table('organisation_invitations', function (Blueprint $table): void {
            $table->dropIndex(['organisation_id', 'email']);
            $table->unique(['organisation_id', 'email']);
        });
    }
};
