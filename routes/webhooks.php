<?php

declare(strict_types=1);

use App\Http\Controllers\Api\BillingController;
use Illuminate\Support\Facades\Route;

// Outside the api group: a webhook needs no session or organisation, and the group's audit entry would record it as a
// plain API call as well. The signature is checked by the handler.
Route::post('billing/webhook', [BillingController::class, 'webhook'])->middleware([
    'audit-api-call:webhook',
    'throttle:30,1',
]);
