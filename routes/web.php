<?php

declare(strict_types=1);

use App\Http\Controllers\InvitationController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::middleware('signed')->group(function (): void {
    Route::get('invitations/{membership}', [InvitationController::class, 'show'])->name('invitations.show');
    Route::post('invitations/{membership}', [InvitationController::class, 'accept'])
        ->middleware('throttle:10,1')->name('invitations.accept');
});
