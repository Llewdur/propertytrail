<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AreaRoomController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\EvidenceController;
use App\Http\Controllers\Api\EvidenceDownloadController;
use App\Http\Controllers\Api\HistoryController;
use App\Http\Controllers\Api\InspectionController;
use App\Http\Controllers\Api\InspectionDeliveryController;
use App\Http\Controllers\Api\InspectionReportController;
use App\Http\Controllers\Api\InspectionSignatureController;
use App\Http\Controllers\Api\InspectionTemplateController;
use App\Http\Controllers\Api\InvitationController;
use App\Http\Controllers\Api\LeaseAreaController;
use App\Http\Controllers\Api\LeaseCaptureLinkController;
use App\Http\Controllers\Api\LeaseCapturePasskeyController;
use App\Http\Controllers\Api\LeaseCaptureSelfieController;
use App\Http\Controllers\Api\LeaseCaptureSignOffController;
use App\Http\Controllers\Api\LeaseController;
use App\Http\Controllers\Api\LeasePhotoController;
use App\Http\Controllers\Api\OrganisationController;
use App\Http\Controllers\Api\PilotEnquiryController;
use App\Http\Controllers\Api\PropertyAssignmentController;
use App\Http\Controllers\Api\PropertyController;
use App\Http\Controllers\Api\PublicCaptureController;
use App\Http\Controllers\Api\PublicCaptureHistoryController;
use App\Http\Controllers\Api\PublicCaptureNoteController;
use App\Http\Controllers\Api\PublicCapturePasskeyController;
use App\Http\Controllers\Api\PublicCaptureSelfieController;
use App\Http\Controllers\Api\PublicCaptureSignOffController;
use App\Http\Controllers\Api\PublicSigningController;
use App\Http\Controllers\Api\RoomReorganiseController;
use App\Http\Controllers\Api\SiteController;
use App\Http\Controllers\Api\SystemController;
use Illuminate\Support\Facades\Route;

Route::get('health', [SystemController::class, 'health']);
Route::get('version', [SystemController::class, 'version']);

Route::middleware('throttle:30,1')->group(function (): void {
    Route::get('sign/{token}', [PublicSigningController::class, 'show']);
    Route::post('sign/{token}', [PublicSigningController::class, 'sign']);
});

// A tenant uploads many photos in one sitting; the token is in a header, not the path.
Route::middleware('throttle:120,1,capture')->group(function (): void {
    Route::get('capture', [PublicCaptureController::class, 'show']);
    Route::post('capture/photos', [PublicCaptureController::class, 'store']);
    Route::post('capture/notes', [PublicCaptureNoteController::class, 'store']);
    Route::post('capture/selfie', [PublicCaptureSelfieController::class, 'store']);
    Route::post('capture/passkey/registration-options', [PublicCapturePasskeyController::class, 'registrationOptions']);
    Route::post('capture/passkey', [PublicCapturePasskeyController::class, 'register']);
    Route::post('capture/unlock/options', [PublicCapturePasskeyController::class, 'verificationOptions']);
    Route::post('capture/unlock', [PublicCapturePasskeyController::class, 'verify']);
    Route::get('capture/photos/{photo}', [PublicCaptureController::class, 'photo'])->whereNumber('photo');
    Route::get('capture/sign-off', [PublicCaptureSignOffController::class, 'show']);
    Route::post('capture/sign-off', [PublicCaptureSignOffController::class, 'store']);
    Route::get('capture/sign-off/copy', [PublicCaptureSignOffController::class, 'copy']);
    Route::get('capture/history', [PublicCaptureHistoryController::class, 'index']);
});

Route::middleware('throttle:6,1')->group(function (): void {
    Route::post('pilot-enquiries', [PilotEnquiryController::class, 'store']);
});

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('organisations', [OrganisationController::class, 'index']);
    Route::post('organisations', [OrganisationController::class, 'store']);
    Route::post('organisations/switch', [OrganisationController::class, 'switch']);
    Route::put('organisations/{organisation}', [OrganisationController::class, 'update']);
    Route::post('organisations/{organisation}/logo', [OrganisationController::class, 'uploadLogo']);
    Route::get('organisations/{organisation}/members', [OrganisationController::class, 'members'])->whereNumber(
        'organisation',
    );
    Route::post('organisations/{organisation}/invitations', [
        OrganisationController::class,
        'invitations',
    ])->whereNumber('organisation');
    Route::post('invitations/{token}/accept', [InvitationController::class, 'accept'])->where('token', '[A-Za-z0-9]+');

    Route::get('properties', [PropertyController::class, 'index']);
    Route::post('properties', [PropertyController::class, 'store']);
    Route::get('properties/{property}', [PropertyController::class, 'show']);
    Route::put('properties/{property}', [PropertyController::class, 'update']);
    Route::delete('properties/{property}', [PropertyController::class, 'destroy']);
    Route::post('properties/{property}/assignments', [PropertyAssignmentController::class, 'store'])->whereNumber(
        'property',
    );
    Route::delete('properties/{property}/assignments/{user}', [
        PropertyAssignmentController::class,
        'destroy',
    ])->whereNumber(['property', 'user']);

    Route::get('sites', [SiteController::class, 'index']);
    Route::post('sites', [SiteController::class, 'store']);
    Route::post('sites/{site}/areas', [SiteController::class, 'storeArea'])->whereNumber('site');
    Route::delete('sites/{site}/areas/{area}', [SiteController::class, 'destroyArea'])->whereNumber(['site', 'area']);

    Route::get('evidence', [EvidenceController::class, 'index']);
    Route::post('evidence/upload', [EvidenceController::class, 'upload']);
    Route::get('inspections', [InspectionController::class, 'index']);
    Route::post('inspections', [InspectionController::class, 'store']);

    Route::middleware('property.access:view')->group(function (): void {
        Route::get('properties/{property}/rooms', [AreaRoomController::class, 'index']);
        Route::get('properties/{property}/leases', [LeaseController::class, 'index']);
        Route::get('properties/{property}/lease-photos', [LeasePhotoController::class, 'index']);
        Route::get('evidence/{evidence}', [EvidenceController::class, 'show'])->whereNumber('evidence');
        Route::get('evidence/{evidence}/download', EvidenceDownloadController::class)->whereNumber('evidence');
        Route::get('leases/{lease}', [LeaseController::class, 'show']);
        Route::get('leases/{lease}/areas', [LeaseAreaController::class, 'show'])->whereNumber('lease');
        Route::get('leases/{lease}/history', [HistoryController::class, 'lease'])->whereNumber('lease');
        Route::get('inspections/{inspection}', [InspectionController::class, 'show'])->whereNumber('inspection');
        Route::get('inspections/{inspection}/deliveries', [InspectionDeliveryController::class, 'index'])->whereNumber(
            'inspection',
        );
        Route::get('inspections/{inspection}/report', [InspectionReportController::class, 'show'])->whereNumber(
            'inspection',
        );
        Route::get('inspections/{inspection}/report/download', [
            InspectionReportController::class,
            'download',
        ])->whereNumber('inspection');
    });

    Route::middleware('property.access:contribute')->group(function (): void {
        Route::put('inspections/{inspection}/sections/{section}', [
            InspectionController::class,
            'updateSection',
        ])->whereNumber(['inspection', 'section']);
        Route::post('inspections/{inspection}/submit', [InspectionController::class, 'submit'])->whereNumber(
            'inspection',
        );
        Route::post('inspections/{inspection}/signatures', [
            InspectionSignatureController::class,
            'store',
        ])->whereNumber('inspection');
        Route::post('inspections/{inspection}/signing-links', [
            InspectionSignatureController::class,
            'issueLink',
        ])->whereNumber('inspection');
        Route::delete('inspections/{inspection}/signing-links', [
            InspectionSignatureController::class,
            'revokeLinks',
        ])->whereNumber('inspection');
        Route::put('inspections/{inspection}/contacts', [
            InspectionDeliveryController::class,
            'saveContact',
        ])->whereNumber('inspection');
        Route::post('inspections/{inspection}/signing-links/send', [
            InspectionDeliveryController::class,
            'sendSigningLink',
        ])
            ->whereNumber('inspection')
            ->middleware('throttle:6,1');
        Route::post('inspections/{inspection}/report/link', [
            InspectionReportController::class,
            'issueLink',
        ])->whereNumber('inspection');
        Route::delete('inspections/{inspection}/report/link', [
            InspectionReportController::class,
            'revokeLinks',
        ])->whereNumber('inspection');
    });

    Route::middleware('property.access:contribute')->group(function (): void {
        Route::get('leases/{lease}/capture-link', [LeaseCaptureLinkController::class, 'show'])->whereNumber('lease');
        Route::post('leases/{lease}/capture-link', [LeaseCaptureLinkController::class, 'store'])->whereNumber('lease');
        Route::delete('leases/{lease}/capture-link', [LeaseCaptureLinkController::class, 'destroy'])->whereNumber(
            'lease',
        );
        Route::post('leases/{lease}/capture-link/send', [LeaseCaptureLinkController::class, 'send'])
            ->whereNumber('lease')
            ->middleware('throttle:6,1');
        Route::get('leases/{lease}/capture-selfies', [LeaseCaptureSelfieController::class, 'index'])->whereNumber(
            'lease',
        );
        Route::get('leases/{lease}/capture-selfies/{selfie}', [
            LeaseCaptureSelfieController::class,
            'show',
        ])->whereNumber(['lease', 'selfie']);
        Route::get('leases/{lease}/capture-sign-offs', [LeaseCaptureSignOffController::class, 'index'])->whereNumber(
            'lease',
        );
        Route::get('leases/{lease}/capture-sign-offs/{signOff}/copy', [
            LeaseCaptureSignOffController::class,
            'copy',
        ])->whereNumber(['lease', 'signOff']);
        Route::get('leases/{lease}/capture-passkeys', [LeaseCapturePasskeyController::class, 'index'])->whereNumber(
            'lease',
        );
        Route::delete('leases/{lease}/capture-passkeys', [
            LeaseCapturePasskeyController::class,
            'destroy',
        ])->whereNumber('lease');
    });

    Route::middleware('property.access:update')->group(function (): void {
        Route::post('properties/{property}/rooms', [AreaRoomController::class, 'store']);
        Route::post('properties/{property}/leases', [LeaseController::class, 'store']);
        Route::put('rooms/{room}', [AreaRoomController::class, 'update']);
        Route::put('leases/{lease}/areas', [LeaseAreaController::class, 'update'])->whereNumber('lease');
        Route::delete('rooms/{room}', [AreaRoomController::class, 'destroy']);
        Route::post('rooms/{room}/merge', [RoomReorganiseController::class, 'merge'])->whereNumber('room');
        Route::post('rooms/{room}/split', [RoomReorganiseController::class, 'split'])->whereNumber('room');
    });

    Route::get('inspection-templates', [InspectionTemplateController::class, 'index']);
    Route::get('inspection-templates/{template}', [InspectionTemplateController::class, 'show'])->whereNumber(
        'template',
    );
    Route::put('inspection-templates/{template}', [InspectionTemplateController::class, 'update'])->whereNumber(
        'template',
    );

    Route::get('audit-logs', [AuditLogController::class, 'index']);
    Route::get('users/{user}/history', [HistoryController::class, 'user'])->whereNumber('user');
    Route::get('billing/entitlement', [BillingController::class, 'show']);
});
