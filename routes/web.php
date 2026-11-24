<?php

declare(strict_types=1);

use App\Http\Controllers\Api\GoogleAuthController;
use App\Http\Controllers\Api\SystemController;
use App\Http\Controllers\Marketing\FaqController;
use App\Http\Controllers\Marketing\LandingController;
use App\Http\Controllers\Marketing\PrivacyController;
use App\Http\Controllers\Marketing\ResourcesController;
use App\Http\Controllers\Marketing\SampleReportController;
use App\Http\Controllers\PublicReportController;
use App\Http\Controllers\SpaController;
use App\Http\Controllers\TermsController;
use App\Http\Controllers\WebLogoutController;
use Illuminate\Support\Facades\Route;

Route::get('/', LandingController::class);
Route::get('terms', TermsController::class);
Route::get('privacy', PrivacyController::class);
Route::get('faq', FaqController::class);
Route::get('resources', ResourcesController::class);
Route::get('resources/{article}', [ResourcesController::class, 'article']);
Route::get('sample-report', SampleReportController::class);
Route::get('sample-report/download', [SampleReportController::class, 'download']);
Route::get('version', [SystemController::class, 'version']);
Route::post('auth/logout', WebLogoutController::class);
Route::get('auth/google', [GoogleAuthController::class, 'redirect']);
Route::get('auth/google/redirect', [GoogleAuthController::class, 'redirect']);
Route::get('auth/google/callback', [GoogleAuthController::class, 'callback']);

Route::get('r/{token}', PublicReportController::class)->middleware('throttle:30,1');

Route::get('app', SpaController::class);
Route::get('app/{path?}', SpaController::class)->where('path', '^(?!(up|storage|spa|build)(/|$)).*$');

Route::get('/{path?}', SpaController::class)->where('path', '^(?!(up|storage|spa|build)(/|$)).*$');
