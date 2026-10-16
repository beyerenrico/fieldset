<?php

declare(strict_types=1);

use App\Http\Api\V1\Controllers\MeController;
use Illuminate\Support\Facades\Route;

/*
| /api/v1 (prefix set in bootstrap/app.php). Sanctum SPA cookies or personal access tokens.
| Tenant-scoped routes add the `organization` middleware (X-Organization-Id).
*/

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('me', MeController::class)->name('api.me');
});
