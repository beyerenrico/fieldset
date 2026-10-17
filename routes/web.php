<?php

declare(strict_types=1);

use App\Domain\Mail\Delivery\DocumentDelivery;
use App\Domain\Mail\Http\DocumentLinkController;
use App\Domain\Reports\Http\ExportDownloadController;
use App\Http\Controllers\Admin\AttachmentDownloadController;
use App\Http\Controllers\Admin\RenditionDownloadController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\SpaController;
use App\Http\Controllers\Sso\CallbackController;
use App\Http\Controllers\Sso\DiscoverController;
use App\Http\Controllers\Sso\RedirectController;
use App\Http\Controllers\Sso\SamlAcsController;
use App\Http\Controllers\Sso\SamlCompleteController;
use App\Http\Controllers\Sso\SamlMetadataController;
use App\Http\Middleware\Security\AdminSessionTimeout;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

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
Route::middleware(['auth', AdminSessionTimeout::class, 'signed'])->group(function (): void {
    Route::get('admin/{organization}/attachments/{attachment}', AttachmentDownloadController::class)->name('admin.attachments.show');
});

// Admin inbox PDF download (M3 review #18): admin session + RenditionPolicy; mints a fresh signed link on click.
Route::get('admin/{organization}/renditions/{rendition}', RenditionDownloadController::class)
    ->middleware(['auth', AdminSessionTimeout::class, 'throttle:60,1'])->name('admin.renditions.download');

// Expiring document links from mails (§8.8): random token, 404 unknown, 410 Gone after expiry.
Route::get('documents/links/{token}', DocumentLinkController::class)
    ->where('token', '[A-Za-z0-9]{'.DocumentDelivery::TOKEN_LENGTH.'}')
    ->middleware('throttle:'.(int) config('messaging.links.rate_limit', 30).',1')
    ->name('documents.link');

// --- M5 reports (BUILD_PROMPT §8.4) ---
// Export downloads: admin session + ReportExportPolicy (requester only); 410 Gone once expired or pruned.
Route::get('admin/{organization}/exports/{export}', ExportDownloadController::class)
    ->middleware(['auth', AdminSessionTimeout::class, 'throttle:60,1'])->name('admin.exports.download');
// --- end M5 reports ---

// --- M7 SSO (BUILD_PROMPT §8.7, ADR 0036) ---
// {provider} is the identity provider's uuid, resolved across organizations (no tenant yet).
Route::prefix('auth/sso')->name('sso.')->group(function (): void {
    Route::post('discover', DiscoverController::class)->middleware('throttle:sso-discover')->name('discover');
    Route::get('saml/complete', SamlCompleteController::class)->name('saml.complete');
    Route::get('{provider}/redirect', RedirectController::class)->whereUuid('provider')->middleware('throttle:30,1')->name('redirect');
    Route::get('{provider}/callback', CallbackController::class)->whereUuid('provider')->middleware('throttle:30,1')->name('callback');
    Route::get('{provider}/saml/metadata', SamlMetadataController::class)->whereUuid('provider')->name('saml.metadata');
    // A cross-site POST from the IdP carries no Lax session cookie and must not start or overwrite a session.
    Route::post('{provider}/saml/acs', SamlAcsController::class)->whereUuid('provider')->middleware('throttle:30,1')
        ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class])
        ->name('saml.acs');
});
// --- end M7 SSO ---
