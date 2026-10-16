<?php

declare(strict_types=1);

namespace App\Http\Api\V1\Middleware;

use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Submissions\Models\IdempotencyKey;
use App\Http\Api\ApiException;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Requires an `Idempotency-Key` and replays the stored response for a repeated key (§9.2). A key reused
 * with a different request body is refused.
 *
 * The request runs in one transaction that first takes an advisory lock on (organization, user, key):
 * a concurrent duplicate waits, then replays; the action's commit and the stored response commit
 * together. Only successful (2xx) responses are stored. Errors such as `attachments_missing` or
 * `form_access_revoked` depend on state the user can fix, so a retry with the same key runs again.
 */
final readonly class IdempotentRequest
{
    public function __construct(private TenantContext $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) $request->headers->get('Idempotency-Key', '');
        if (preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $key) !== 1) {
            throw new ApiException('idempotency_key_required', __('submissions.errors.idempotency_key_required'), 400);
        }

        $userId = (int) $request->user()?->getAuthIdentifier();
        $hash = hash('sha256', $request->method().' '.$request->path().' '.$request->getContent());

        DB::beginTransaction();
        try {
            $response = $this->process($request, $next, $userId, $key, $hash);
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }
        $response->isSuccessful() ? DB::commit() : DB::rollBack();

        return $response;
    }

    private function process(Request $request, Closure $next, int $userId, string $key, string $hash): Response
    {
        DB::select('SELECT pg_advisory_xact_lock(?)', [$this->lockId($userId, $key)]);

        $stored = IdempotencyKey::query()->where('user_id', $userId)->where('key', $key)->first();
        if ($stored !== null) {
            if (! hash_equals($stored->request_hash, $hash)) {
                throw new ApiException('idempotency_key_reused', __('submissions.errors.idempotency_key_reused'), 409);
            }

            return new JsonResponse($stored->response, $stored->status_code, ['Idempotent-Replayed' => 'true'], 0, true);
        }

        /** @var Response $response */
        $response = $next($request);

        if ($response instanceof JsonResponse && $response->isSuccessful()) {
            IdempotencyKey::query()->create([
                'user_id' => $userId, 'key' => $key, 'request_hash' => $hash,
                'status_code' => $response->getStatusCode(), 'response' => (string) $response->getContent(),
            ]);
        }

        return $response;
    }

    /** A signed 60-bit lock id for pg_advisory_xact_lock(bigint). */
    private function lockId(int $userId, string $key): int
    {
        return (int) hexdec(substr(hash('sha256', 'idempotency|'.$this->tenant->require().'|'.$userId.'|'.$key), 0, 15));
    }
}
