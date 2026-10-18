<?php

declare(strict_types=1);

namespace App\Http\Controllers\Instance;

use App\Domain\Identity\Models\User;
use App\Domain\Platform\Auth\CatcherAccess;
use App\Http\Controllers\Controller;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The target of the edge's `forward_auth` for `/_mail/` in domain mode (plan R66, KTD10, ADR 0075): 200 lets the
 * catcher request through, anything else goes back to the browser as it is. Caddy sends the original request's
 * headers (cookie, Accept, Sec-Fetch-*, Sec-WebSocket-Key; the edge strips Upgrade, Connection and Authorization) as
 * a GET. A browser navigation without access is redirected to the /platform sign-in; Mailpit's XHR, fetch and
 * websocket requests get 401, which its UI can handle (a redirect would hand it an HTML login page).
 */
final class CatcherAuthController extends Controller
{
    public function __invoke(Request $request, CatcherAccess $access): Response
    {
        $session = $request->hasSession() ? $request->session() : null;
        $user = $session === null ? null : $access->allowedUser($session);
        if ($user instanceof User && $session !== null) {
            $access->recordOpening($user, $session->getId());

            return $this->noStore(response('ok', Response::HTTP_OK, ['Content-Type' => 'text/plain; charset=UTF-8']));
        }

        return $this->noStore($this->isNavigation($request)
            ? redirect()->to(Filament::getPanel('platform')->getLoginUrl() ?? url('/platform/login'))
            : response('Unauthorized', Response::HTTP_UNAUTHORIZED, ['Content-Type' => 'text/plain; charset=UTF-8']));
    }

    private function isNavigation(Request $request): bool
    {
        if ($request->headers->has('Sec-WebSocket-Key') || $request->headers->get('X-Requested-With') === 'XMLHttpRequest') {
            return false;
        }
        $mode = $request->headers->get('Sec-Fetch-Mode');
        if (is_string($mode) && $mode !== '') {
            return strtolower($mode) === 'navigate'; // cors, no-cors, same-origin, websocket: fetches and sockets
        }

        return str_contains(strtolower((string) $request->headers->get('Accept')), 'text/html');
    }

    private function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
