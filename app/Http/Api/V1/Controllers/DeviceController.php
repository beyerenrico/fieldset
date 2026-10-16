<?php

declare(strict_types=1);

namespace App\Http\Api\V1\Controllers;

use App\Domain\Identity\Devices\DeviceStatus;
use App\Domain\Identity\Devices\RegisterDevice;
use App\Domain\Identity\Models\User;
use App\Http\Api\ApiException;
use App\Http\Api\ApiResponse;
use App\Http\Middleware\EndRevokedDeviceSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Device registration and the session-independent device status check (§9.3). */
final class DeviceController
{
    public function store(Request $request, RegisterDevice $register): JsonResponse
    {
        $data = $request->validate([
            'device_uuid' => ['required', 'uuid'],
            'name' => ['nullable', 'string', 'max:120'],
            'platform' => ['nullable', 'string', 'max:60'],
        ]);
        /** @var User $user */
        $user = $request->user();
        [$device, $secret] = $register->handle($user, $data['device_uuid'], $data['name'] ?? null, $data['platform'] ?? null);
        if ($request->hasSession()) {
            // Bind this session to the device so revoking the device ends it (§9.3).
            $request->session()->put(EndRevokedDeviceSession::SESSION_KEY, $device->id);
        }

        return ApiResponse::ok(['device_uuid' => $device->device_uuid, 'secret' => $secret], null, 201);
    }

    public function status(Request $request, DeviceStatus $status): JsonResponse
    {
        $uuid = (string) $request->headers->get('X-Device-Id', '');
        $secret = (string) $request->headers->get('X-Device-Secret', '');
        $sessionUser = auth()->guard('sanctum')->user();
        $result = $uuid !== '' && $secret !== '' ? $status->resolve($uuid, $secret, $sessionUser instanceof User ? $sessionUser : null) : null;

        if ($result === null) {
            throw new ApiException('device_unknown', __('submissions.errors.device_unknown'), 401);
        }

        return ApiResponse::ok(['status' => $result]);
    }
}
