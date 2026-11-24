<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AreaRoomController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\EvidenceController;
use App\Http\Controllers\Api\InspectionController;
use App\Http\Controllers\Api\LeaseController;
use App\Http\Controllers\Api\LeasePhotoController;
use App\Http\Controllers\Api\OrganisationController;
use App\Http\Controllers\Api\PropertyController;
use App\Http\Controllers\Api\SystemController;
use Illuminate\Support\Facades\Route;

Route::get('health', [SystemController::class, 'health']);
Route::get('version', [SystemController::class, 'version']);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('organisations', [OrganisationController::class, 'index']);
    Route::post('organisations', [OrganisationController::class, 'store']);
    Route::post('organisations/switch', [OrganisationController::class, 'switch']);
    Route::put('organisations/{organisation}', [OrganisationController::class, 'update']);

    Route::get('properties', [PropertyController::class, 'index']);
    Route::post('properties', [PropertyController::class, 'store']);
    Route::get('properties/{property}', [PropertyController::class, 'show']);
    Route::put('properties/{property}', [PropertyController::class, 'update']);
    Route::delete('properties/{property}', [PropertyController::class, 'destroy']);

    Route::get('properties/{property}/rooms', [AreaRoomController::class, 'index']);
    Route::post('properties/{property}/rooms', [AreaRoomController::class, 'store']);
    Route::get('properties/{property}/leases', [LeaseController::class, 'index']);
    Route::post('properties/{property}/leases', [LeaseController::class, 'store']);
    Route::get('properties/{property}/lease-photos', [LeasePhotoController::class, 'index']);
    Route::put('rooms/{room}', [AreaRoomController::class, 'update']);
    Route::delete('rooms/{room}', [AreaRoomController::class, 'destroy']);

    Route::get('evidence', [EvidenceController::class, 'index']);
    Route::post('evidence/upload', [EvidenceController::class, 'upload']);
    Route::get('evidence/{evidence}', [EvidenceController::class, 'show']);
    Route::get('leases/{lease}', [LeaseController::class, 'show']);

    Route::get('inspections', [InspectionController::class, 'index']);
    Route::post('inspections', [InspectionController::class, 'store']);
    Route::get('inspections/{inspection}', [InspectionController::class, 'show'])->whereNumber('inspection');
    Route::put('inspections/{inspection}/sections/{section}', [
        InspectionController::class,
        'updateSection',
    ])->whereNumber(['inspection', 'section']);
    Route::post('inspections/{inspection}/submit', [InspectionController::class, 'submit'])->whereNumber('inspection');

    Route::get('audit-logs', [AuditLogController::class, 'index']);
});
