<?php

use App\Models\AuthEvent;
use App\Services\Billing\EntitlementService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(fn() => app(EntitlementService::class)->flushOutbox())
    ->name('fleet-accounting-flush-billing-outbox')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('model:prune', ['--model' => [AuthEvent::class]])->daily();

Schedule::command('capture:send-reminders')->hourly()->withoutOverlapping();
