<?php

declare(strict_types=1);

namespace App\Http\Api;

use App\Domain\Identity\Tenancy\TenantNotSetException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Renders every exception on /api/* as the §10 envelope without leaking internals. */
final class ApiExceptionRenderer
{
    public function render(Throwable $e): JsonResponse
    {
        return match (true) {
            $e instanceof ApiException => ApiResponse::error($e->errorCode, $e->getMessage(), $e->status, $e->details),
            $e instanceof ValidationException => $this->validation($e),
            $e instanceof AuthenticationException => ApiResponse::error('unauthenticated', __('api.unauthenticated'), 401),
            $e instanceof AuthorizationException => ApiResponse::error('forbidden', __('api.forbidden'), 403),
            $e instanceof ModelNotFoundException => ApiResponse::error('not_found', __('api.not_found'), 404),
            $e instanceof TenantNotSetException => ApiResponse::error('organization_required', __('api.organization_required'), 400),
            $e instanceof ThrottleRequestsException => ApiResponse::error('too_many_requests', __('api.too_many_requests'), 429, null, $this->headers($e)),
            $e instanceof HttpExceptionInterface => $this->http($e),
            default => ApiResponse::error('server_error', __('api.server_error'), 500),
        };
    }

    private function validation(ValidationException $e): JsonResponse
    {
        $failed = $e->validator->failed();
        $details = [];

        foreach ($e->errors() as $field => $messages) {
            $rules = array_keys($failed[$field] ?? []);
            foreach ($messages as $i => $message) {
                $details[] = [
                    'pointer' => ApiResponse::pointer((string) $field),
                    'code' => isset($rules[$i]) ? Str::snake((string) $rules[$i]) : 'invalid',
                    'message' => $message,
                ];
            }
        }

        return ApiResponse::error('validation_failed', __('api.validation_failed'), 422, $details);
    }

    private function http(HttpExceptionInterface $e): JsonResponse
    {
        $status = $e->getStatusCode();
        $code = match ($status) {
            403 => 'forbidden',
            404 => 'not_found',
            405 => 'method_not_allowed',
            409 => 'conflict',
            410 => 'gone',
            419 => 'session_expired',
            default => $status >= 500 ? 'server_error' : 'http_error',
        };

        return ApiResponse::error($code, __('api.'.$code), $status, null, $this->headers($e));
    }

    /** @return array<string, string> */
    private function headers(HttpExceptionInterface $e): array
    {
        return array_map(static fn (mixed $v): string => (string) (is_array($v) ? implode(',', $v) : $v), $e->getHeaders());
    }
}
