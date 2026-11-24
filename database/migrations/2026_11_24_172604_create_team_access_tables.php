<?php

declare(strict_types=1);

use App\Enums\OrganisationRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        DB::table('organisation_user')
            ->whereNotIn('role', OrganisationRole::values())
            ->update(['role' => OrganisationRole::Viewer->value]);

        Schema::create('property_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default(OrganisationRole::PropertyManager->value);
            $table->timestamps();

            $table->unique(['property_id', 'user_id']);
            $table->index(['organisation_id', 'user_id']);
        });

        Schema::create('organisation_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('role');
            $table->string('token_hash')->unique();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->unique(['organisation_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organisation_invitations');
        Schema::dropIfExists('property_user');
    }
};
