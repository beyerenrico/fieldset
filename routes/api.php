<?php

declare(strict_types=1);

use App\Domain\Identity\Tokens\TokenAbility;
use App\Http\Api\V1\Controllers\AttachmentController;
use App\Http\Api\V1\Controllers\CustomerSendController;
use App\Http\Api\V1\Controllers\DeviceController;
use App\Http\Api\V1\Controllers\DocumentController;
use App\Http\Api\V1\Controllers\FormController;
use App\Http\Api\V1\Controllers\MeController;
use App\Http\Api\V1\Controllers\NotificationController;
use App\Http\Api\V1\Controllers\NotificationPreferenceController;
use App\Http\Api\V1\Controllers\SubmissionController;
use App\Http\Api\V1\Controllers\SyncController;
use App\Http\Api\V1\Controllers\TaskController;
use App\Http\Api\V1\Middleware\IdempotentRequest;
use App\Http\Middleware\Api\AuthenticateApiToken;
use App\Http\Middleware\Api\RequireTokenAbility as Ability;
use Illuminate\Support\Facades\Route;

/*
| /api/v1 (prefix set in bootstrap/app.php). Contract: docs/api/field-app-v1.md.
| Tenant-scoped routes add the `organization` middleware (X-Organization-Id).
| Every authenticated route declares the abilities an integration token needs (ADR 0043); a route without
| a declaration refuses tokens. Field App sessions are not affected by the ability checks.
*/

Route::post('auth/device-status', [DeviceController::class, 'status'])->middleware('throttle:30,1')->name('api.devices.status');
Route::get('documents/{rendition}', [DocumentController::class, 'download'])->middleware(['signed', 'throttle:60,1'])->name('documents.download');

Route::middleware(['auth:sanctum', AuthenticateApiToken::class])->group(function (): void {
    // --- Field App only: integration tokens get 403 session_required ---
    Route::middleware(Ability::sessionOnly())->group(function (): void {
        Route::get('me', MeController::class)->name('api.me');
        Route::post('devices', [DeviceController::class, 'store'])->name('api.devices.store');
        Route::put('me/notification-preferences', [NotificationPreferenceController::class, 'update'])->name('api.notification-preferences.update');
    });

    // --- Notifications (M4-T06): user-level, no organization header ---
    Route::middleware(Ability::using(TokenAbility::NotificationsRead))->group(function (): void {
        Route::get('notifications', [NotificationController::class, 'index'])->name('api.notifications.index');
        Route::post('notifications/read', [NotificationController::class, 'read'])->name('api.notifications.read');
        Route::get('me/notification-preferences', [NotificationPreferenceController::class, 'show'])->name('api.notification-preferences.show');
    });
    // --- end Notifications ---

    Route::middleware('organization')->group(function (): void {
        Route::middleware(Ability::using(TokenAbility::FormsRead))->group(function (): void {
            Route::get('forms', [FormController::class, 'index'])->name('api.forms.index');
            Route::get('forms/{form}/versions/{version}', [FormController::class, 'version'])
                ->whereNumber(['form', 'version'])->name('api.forms.version');
        });
        Route::get('sync/manifest', [SyncController::class, 'manifest'])
            ->middleware(Ability::using(TokenAbility::FormsRead, TokenAbility::TasksRead))->name('api.sync.manifest');

        Route::middleware(Ability::using(TokenAbility::SubmissionsRead))->group(function (): void {
            Route::get('submissions', [SubmissionController::class, 'index'])->name('api.submissions.index');
            Route::get('submissions/{uuid}', [SubmissionController::class, 'show'])->name('api.submissions.show');
            Route::get('submissions/{uuid}/attachments/{attachment}', [AttachmentController::class, 'show'])->name('api.attachments.show');
        });
        Route::middleware(Ability::using(TokenAbility::SubmissionsWrite))->group(function (): void {
            Route::put('submissions/{uuid}', [SubmissionController::class, 'upsert'])->name('api.submissions.upsert');
            Route::post('submissions/{uuid}/submit', [SubmissionController::class, 'submit'])
                ->middleware(IdempotentRequest::class)->name('api.submissions.submit');
            Route::post('submissions/{uuid}/attachments', [AttachmentController::class, 'store'])
                ->middleware('throttle:120,1')->name('api.attachments.store');
        });
        Route::get('submissions/{uuid}/document', [DocumentController::class, 'show'])
            ->middleware(Ability::using(TokenAbility::DocumentsRead))->name('api.submissions.document');
        // Emailing the PDF to any address also discloses it: a token needs both abilities.
        Route::post('submissions/{uuid}/send', [CustomerSendController::class, 'store'])
            ->middleware([Ability::using(TokenAbility::SubmissionsWrite, TokenAbility::DocumentsRead), IdempotentRequest::class, 'throttle:30,1'])
            ->name('api.submissions.send');

        // --- Review tasks (M4-T07) ---
        Route::get('tasks', [TaskController::class, 'index'])
            ->middleware(Ability::using(TokenAbility::TasksRead))->name('api.tasks.index');
        Route::post('tasks/{id}/complete', [TaskController::class, 'complete'])
            ->middleware([Ability::using(TokenAbility::TasksWrite), IdempotentRequest::class])->name('api.tasks.complete');
        // --- end Review tasks ---
    });
});
