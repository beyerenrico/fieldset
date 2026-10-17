<?php

declare(strict_types=1);

namespace App\Http\Api\V1\Controllers;

use App\Domain\Identity\Models\User;
use App\Domain\Mail\Actions\RequestCustomerSend;
use App\Domain\Mail\CustomerSend\CustomerRecipient;
use App\Domain\Mail\CustomerSend\CustomerSendGate;
use App\Domain\Mail\CustomerSend\CustomerSendLimitExceeded;
use App\Domain\Mail\CustomerSend\DocumentSendPresenter;
use App\Domain\Mail\Models\DocumentSend;
use App\Domain\Submissions\Models\Submission;
use App\Domain\Submissions\Support\SubmissionVisibility;
use App\Http\Api\ApiException;
use App\Http\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /submissions/{uuid}/send (§9.2 "send to customer", §10). Runs behind IdempotentRequest. Order of
 * checks: invisible → 404, refused → 409 (`not_submitted`, `send_not_allowed`), recipient → 422, caps per
 * submission and organization → 429 `too_many_requests`.
 *
 * A key that already created a send for this submission (a lost response replayed after IdempotentRequest
 * pruned its stored copy, M6) answers 202 with that send right after the visibility check, before the
 * refusal, recipient and cap checks: the request it repeats was accepted then.
 */
final readonly class CustomerSendController
{
    public function __construct(private SubmissionVisibility $visibility) {}

    public function store(Request $request, string $uuid, RequestCustomerSend $send): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $submission = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid) === 1
            ? Submission::query()->whereKey(strtolower($uuid))->first()
            : null;
        if ($submission === null || ! $this->visibility->canView($user, $submission)) {
            throw new ApiException('not_found', __('api.not_found'), 404);
        }

        $key = (string) $request->headers->get('Idempotency-Key', '');
        $existing = RequestCustomerSend::existing($submission, $key);
        if ($existing instanceof DocumentSend) {
            return ApiResponse::ok(['send' => DocumentSendPresenter::present($existing)], null, 202);
        }

        $refusal = CustomerSendGate::refusal($user, $submission);
        if ($refusal !== null) {
            throw new ApiException($refusal, (string) __('mail.customer_send.errors.'.$refusal), 409);
        }

        $recipient = CustomerRecipient::resolve($submission, $request->input('email'), $request->input('name'), $request->exists('name'));
        try {
            $result = $send->handle($user, $submission, $recipient, $key);
        } catch (CustomerSendLimitExceeded $e) {
            throw new ApiException('too_many_requests', $e->getMessage(), 429);
        }

        return ApiResponse::ok(['send' => DocumentSendPresenter::present($result)], null, 202);
    }
}
