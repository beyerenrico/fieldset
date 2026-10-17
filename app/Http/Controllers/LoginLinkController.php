<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Identity\LoginLinks\ConsumeLoginLink;
use App\Domain\Identity\LoginLinks\LoginLinkHandoff;
use App\Domain\Identity\LoginLinks\LoginLinkResult;
use App\Domain\Identity\LoginLinks\LoginLinkState;
use App\Domain\Identity\Models\LoginLink;
use App\Domain\Identity\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * The one-time sign-in link landing page (BUILD_PROMPT_V2 R27/R28, ADR 0056). The token travels in the URL
 * fragment, which browsers never send: the GET renders a page whose inline script moves the fragment into a
 * form and POSTs it, so the token never appears in a request line or an access log. Only the POST consumes it.
 */
final readonly class LoginLinkController
{
    public function show(): View
    {
        return view('login-link.landing', ['state' => LoginLinkState::Valid]);
    }

    public function consume(Request $request, ConsumeLoginLink $consume, LoginLinkHandoff $handoff): Response|RedirectResponse
    {
        $token = $request->input('token');
        $token = is_string($token) ? trim($token) : '';
        $result = $consume->handle($token, (string) $request->ip());

        if ($result->state === LoginLinkState::Valid && $result->link instanceof LoginLink && $result->user instanceof User) {
            return redirect()->to($handoff->continue($result->link, $result->user, $token), 303);
        }

        return $this->refused($result);
    }

    private function refused(LoginLinkResult $result): Response
    {
        $response = response()->view('login-link.landing', ['state' => $result->state], $result->state->httpStatus());
        if ($result->retryAfter !== null) {
            $response->header('Retry-After', (string) $result->retryAfter);
        }

        return $response;
    }
}
