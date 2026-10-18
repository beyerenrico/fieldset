<?php

declare(strict_types=1);

namespace App\Http\Controllers\Instance;

use App\Domain\Instance\Runtime\HostName;
use App\Domain\Instance\Runtime\PhoneTrust;
use App\Domain\Instance\Runtime\PhoneTrustRoute;
use App\Domain\Instance\Runtime\RuntimeStateReader;
use App\Http\Middleware\Security\ContentSecurityPolicy;
use App\Http\Middleware\Security\SecureTransport;
use App\Http\Middleware\Security\Surface;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The phone trust page of local-CA mode (R19, ADR 0057, ADR 0082) and the CA's root certificate. The edge serves both
 * on plain HTTP (port 80) as well as HTTPS, without sign-in: the routes run without session, cookies, CSRF and the
 * locale/device middleware, so a remember-me cookie never signs anyone in here. Outside local-CA mode both are 404.
 */
final readonly class PhoneTrustController
{
    /** German first: the product's default language when the browser asks for neither. */
    private const array LOCALES = ['de', 'en'];

    /** Pairs of the fingerprint per group, so it wraps between groups at phone width. */
    private const int FINGERPRINT_GROUP = 4;

    private const int QR_SIZE = 232;

    public const string DOWNLOAD_NAME = 'form-schema-app-local-ca.crt';

    public function __construct(private RuntimeStateReader $reader, private Repository $config) {}

    public function show(Request $request): Response
    {
        $trust = $this->trust();
        app()->setLocale($this->locale($request));

        return response()->view('phone-trust', [
            'trust' => $trust,
            'fingerprintGroups' => $this->groups($trust->root->fingerprint()),
            'qrCode' => $this->qrCode($trust->fieldAppUrl()),
            'checkable' => HostName::normalize($request->getHost()) === $trust->host(),
            'trustPageUrl' => 'http://'.$trust->host().PhoneTrustRoute::PATH,
        ], 200, ['Cache-Control' => 'no-store, private', 'Content-Security-Policy' => $this->policy($request, $trust)]);
    }

    public function root(): Response
    {
        return response($this->trust()->root->pem(), 200, [
            'Content-Type' => 'application/x-x509-ca-cert',
            'Content-Disposition' => 'attachment; filename="'.self::DOWNLOAD_NAME.'"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * The Html policy (SecurityHeaders keeps a policy set here), plus the instance's HTTPS origin for the trust check's
     * image. Runs after AllowPlainHttpPage, so upgrade-insecure-requests follows the request's own scheme.
     */
    private function policy(Request $request, PhoneTrust $trust): string
    {
        $policy = new ContentSecurityPolicy($request->getSchemeAndHttpHost(), Vite::cspNonce(), SecureTransport::expected($request), null, [$trust->instanceUrl]);

        return $policy->for(Surface::Html);
    }

    private function trust(): PhoneTrust
    {
        return PhoneTrust::current($this->reader, $this->config) ?? throw new NotFoundHttpException;
    }

    /** `?lang=de|en`, else the browser's preference, else the app default; never the session or a user setting. */
    private function locale(Request $request): string
    {
        $requested = $request->query('lang');
        if (is_string($requested) && in_array($requested, self::LOCALES, true)) {
            return $requested;
        }
        $preferred = $request->getPreferredLanguage(self::LOCALES);

        return is_string($preferred) ? $preferred : (string) $this->config->get('app.locale', self::LOCALES[0]);
    }

    /** @return list<string> `AB:CD:EF:01:` … the last group without a trailing colon; joined they are the fingerprint */
    private function groups(string $fingerprint): array
    {
        $groups = array_map(
            static fn (array $pairs): string => implode(':', $pairs),
            array_chunk(explode(':', $fingerprint), self::FINGERPRINT_GROUP),
        );
        $last = count($groups) - 1;

        return array_values(array_map(static fn (string $group, int $index): string => $index < $last ? $group.':' : $group, $groups, array_keys($groups)));
    }

    /**
     * An inline SVG QR code with the standard quiet zone of four modules: the XML declaration dropped, the drawing
     * hidden from assistive technology (the view labels its container as one image and prints the URL).
     */
    private function qrCode(string $text): string
    {
        $svg = new Writer(new ImageRenderer(new RendererStyle(self::QR_SIZE), new SvgImageBackEnd))->writeString($text);
        $svg = trim(substr($svg, (int) strpos($svg, "\n") + 1));

        return (string) preg_replace('/^<svg\b/', '<svg aria-hidden="true" focusable="false"', $svg, 1);
    }
}
