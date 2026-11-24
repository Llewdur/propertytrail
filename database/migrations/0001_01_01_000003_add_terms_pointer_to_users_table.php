<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('users', 'terms_accepted_at') && Schema::hasColumn('users', 'terms_version')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            if (!Schema::hasColumn('users', 'terms_accepted_at')) {
                $table->timestamp('terms_accepted_at')->nullable();
            }

            if (!Schema::hasColumn('users', 'terms_version')) {
                $table->string('terms_version')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('users', 'terms_accepted_at') || !Schema::hasColumn('users', 'terms_version')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['terms_accepted_at', 'terms_version']);
        });
    }
};
