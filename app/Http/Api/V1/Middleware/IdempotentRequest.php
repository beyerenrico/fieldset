<?php

declare(strict_types=1);

namespace App\Http\Api\V1\Middleware;

use App\Domain\Submissions\Models\IdempotencyKey;
use App\Http\Api\ApiException;
use Closure;
use FormSchema\Json\Json;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires an `Idempotency-Key` and replays the stored response for a repeated key (§9.2). A key reused
 * with a different request body is refused. Server errors (5xx) are not stored, so they can be retried.
 */
final class IdempotentRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) $request->headers->get('Idempotency-Key', '');
        if (preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $key) !== 1) {
            throw new ApiException('idempotency_key_required', __('submissions.errors.idempotency_key_required'), 400);
        }

        $userId = (int) $request->user()?->getAuthIdentifier();
        $hash = hash('sha256', $request->method().' '.$request->path().' '.$request->getContent());
        $stored = IdempotencyKey::query()->where('user_id', $userId)->where('key', $key)->first();

        if ($stored !== null) {
            if ($stored->request_hash !== $hash) {
                throw new ApiException('idempotency_key_reused', __('submissions.errors.idempotency_key_reused'), 409);
            }

            return new JsonResponse(json_decode(Json::encode($stored->response), true), $stored->status_code, ['Idempotent-Replayed' => 'true']);
        }

        $response = $next($request);

        if ($response instanceof JsonResponse && $response->getStatusCode() < 500) {
            try {
                IdempotencyKey::query()->create([
                    'user_id' => $userId, 'key' => $key, 'request_hash' => $hash,
                    'status_code' => $response->getStatusCode(), 'response' => Json::decode((string) $response->getContent()),
                ]);
            } catch (UniqueConstraintViolationException) {
                // A concurrent duplicate already stored its response; ours is equivalent.
            }
        }

        return $response;
    }
}
