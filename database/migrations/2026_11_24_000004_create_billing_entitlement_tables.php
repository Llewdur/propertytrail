<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('subscriptions', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->string('plan_code', 40);
            $table->string('status', 24)->default('active');
            $table->string('external_customer_id')->nullable();
            $table->string('external_subscription_id')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index(['organisation_id', 'status']);
        });

        Schema::create('usage_records', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 32);
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            $table->date('period_start');
            $table->timestamps();

            $table->unique(['organisation_id', 'kind', 'subject_type', 'subject_id']);
            $table->index(['organisation_id', 'kind', 'period_start']);
        });

        Schema::create('billing_event_outboxes', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->string('event_type', 48);
            $table->string('external_reference_id')->unique();
            $table->jsonb('payload');
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_event_outboxes');
        Schema::dropIfExists('usage_records');
        Schema::dropIfExists('subscriptions');
    }
};
