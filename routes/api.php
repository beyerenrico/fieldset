<?php

declare(strict_types=1);

use App\Http\Api\V1\Controllers\AttachmentController;
use App\Http\Api\V1\Controllers\CustomerSendController;
use App\Http\Api\V1\Controllers\DeviceController;
use App\Http\Api\V1\Controllers\DocumentController;
use App\Http\Api\V1\Controllers\FormController;
use App\Http\Api\V1\Controllers\MeController;
use App\Http\Api\V1\Controllers\SubmissionController;
use App\Http\Api\V1\Controllers\SyncController;
use App\Http\Api\V1\Middleware\IdempotentRequest;
use Illuminate\Support\Facades\Route;

/*
| /api/v1 (prefix set in bootstrap/app.php). Contract: docs/api/field-app-v1.md.
| Tenant-scoped routes add the `organization` middleware (X-Organization-Id).
*/

Route::post('auth/device-status', [DeviceController::class, 'status'])->middleware('throttle:30,1')->name('api.devices.status');
Route::get('documents/{rendition}', [DocumentController::class, 'download'])->middleware(['signed', 'throttle:60,1'])->name('documents.download');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('me', MeController::class)->name('api.me');
    Route::post('devices', [DeviceController::class, 'store'])->name('api.devices.store');

    Route::middleware('organization')->group(function (): void {
        Route::get('forms', [FormController::class, 'index'])->name('api.forms.index');
        Route::get('forms/{form}/versions/{version}', [FormController::class, 'version'])
            ->whereNumber(['form', 'version'])->name('api.forms.version');
        Route::get('sync/manifest', [SyncController::class, 'manifest'])->name('api.sync.manifest');

        Route::get('submissions', [SubmissionController::class, 'index'])->name('api.submissions.index');
        Route::get('submissions/{uuid}', [SubmissionController::class, 'show'])->name('api.submissions.show');
        Route::put('submissions/{uuid}', [SubmissionController::class, 'upsert'])->name('api.submissions.upsert');
        Route::post('submissions/{uuid}/submit', [SubmissionController::class, 'submit'])
            ->middleware(IdempotentRequest::class)->name('api.submissions.submit');
        Route::post('submissions/{uuid}/attachments', [AttachmentController::class, 'store'])
            ->middleware('throttle:120,1')->name('api.attachments.store');
        Route::get('submissions/{uuid}/attachments/{attachment}', [AttachmentController::class, 'show'])->name('api.attachments.show');
        Route::get('submissions/{uuid}/document', [DocumentController::class, 'show'])->name('api.submissions.document');
        Route::post('submissions/{uuid}/send', [CustomerSendController::class, 'store'])
            ->middleware([IdempotentRequest::class, 'throttle:30,1'])->name('api.submissions.send');
    });
});
