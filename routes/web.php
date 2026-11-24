<?php

declare(strict_types=1);

use App\Http\Controllers\Api\GoogleAuthController;
use App\Http\Controllers\Api\SystemController;
use App\Http\Controllers\SpaController;
use App\Http\Controllers\TermsController;
use App\Http\Controllers\WebLogoutController;
use Illuminate\Support\Facades\Route;

Route::get('terms', TermsController::class);
Route::get('version', [SystemController::class, 'version']);
Route::post('auth/logout', WebLogoutController::class);
Route::get('auth/google', [GoogleAuthController::class, 'redirect']);
Route::get('auth/google/redirect', [GoogleAuthController::class, 'redirect']);
Route::get('auth/google/callback', [GoogleAuthController::class, 'callback']);

Route::get('/{path?}', SpaController::class)->where('path', '^(?!(up|storage|spa|build)(/|$)).*$');
