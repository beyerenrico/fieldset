<?php

declare(strict_types=1);

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
