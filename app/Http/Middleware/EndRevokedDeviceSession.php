<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Identity\Models\Device;
use App\Http\Api\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Revoking a device revokes its sessions (§9.3): a session bound to a device at registration ends on
 * its next request once the device is revoked, wiped or its user deactivated. The Field App then
 * calls device-status and receives `wipe`.
 */
final class EndRevokedDeviceSession
{
    public const string SESSION_KEY = 'device_id';

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession()) {
            return $next($request);
        }
        $deviceId = $request->session()->get(self::SESSION_KEY);
        if (! is_int($deviceId)) {
            return $next($request);
        }

        $device = Device::query()->with('user')->find($deviceId);
        if ($device !== null && ! $device->mustWipe() && $device->user_id === Auth::guard('web')->id()) {
            return $next($request);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $request->is('api/*') || $request->expectsJson()
            ? ApiResponse::error('device_revoked', __('submissions.errors.device_revoked'), 401)
            : redirect()->guest('/admin/login');
    }
}
