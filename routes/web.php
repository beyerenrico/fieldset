<?php

declare(strict_types=1);

use App\Domain\Mail\Delivery\DocumentDelivery;
use App\Domain\Mail\Http\DocumentLinkController;
use App\Domain\Reports\Http\ExportDownloadController;
use App\Http\Controllers\Admin\AttachmentDownloadController;
use App\Http\Controllers\Admin\RenditionDownloadController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\SpaController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::middleware('signed')->group(function (): void {
    Route::get('invitations/{membership}', [InvitationController::class, 'show'])->name('invitations.show');
    Route::post('invitations/{membership}', [InvitationController::class, 'accept'])
        ->middleware('throttle:10,1')->name('invitations.accept');
});

// Field App (React PWA) and the builder preview entry (BUILD_PROMPT §6.1).
Route::get('app/{path?}', [SpaController::class, 'app'])->where('path', '.*')->name('field-app');
Route::get('form-preview/{path?}', [SpaController::class, 'preview'])->where('path', '.*')->name('form-preview');

// Admin submissions inbox attachments (M3-T09): admin session + expiring signature; the controller authorizes.
Route::middleware(['auth', 'signed'])->group(function (): void {
    Route::get('admin/{organization}/attachments/{attachment}', AttachmentDownloadController::class)->name('admin.attachments.show');
});

// Admin inbox PDF download (M3 review #18): admin session + RenditionPolicy; mints a fresh signed link on click.
Route::get('admin/{organization}/renditions/{rendition}', RenditionDownloadController::class)
    ->middleware(['auth', 'throttle:60,1'])->name('admin.renditions.download');

// Expiring document links from mails (§8.8): random token, 404 unknown, 410 Gone after expiry.
Route::get('documents/links/{token}', DocumentLinkController::class)
    ->where('token', '[A-Za-z0-9]{'.DocumentDelivery::TOKEN_LENGTH.'}')
    ->middleware('throttle:'.(int) config('messaging.links.rate_limit', 30).',1')
    ->name('documents.link');

// --- M5 reports (BUILD_PROMPT §8.4) ---
// Export downloads: admin session + ReportExportPolicy (requester only); 410 Gone once expired or pruned.
Route::get('admin/{organization}/exports/{export}', ExportDownloadController::class)
    ->middleware(['auth', 'throttle:60,1'])->name('admin.exports.download');
// --- end M5 reports ---
