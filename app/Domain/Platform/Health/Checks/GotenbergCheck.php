<?php

declare(strict_types=1);

namespace App\Domain\Platform\Health\Checks;

use App\Domain\Platform\Health\HealthCheck;
use App\Domain\Platform\Health\HealthResult;
use Illuminate\Support\Facades\Http;

/**
 * Gotenberg (PDF rendering) via its `/health` endpoint with a 2 s timeout. The URL is operator configuration
 * (laravel-pdf.gotenberg.url, usually a private host), not tenant input, so the SSRF guard does not apply.
 * Only scheme, host and port are shown, never credentials.
 */
final readonly class GotenbergCheck implements HealthCheck
{
    public function key(): string
    {
        return 'gotenberg';
    }

    public function run(): HealthResult
    {
        $base = rtrim((string) config('laravel-pdf.gotenberg.url', ''), '/');
        $details = [(string) __('platform.health.endpoint') => $this->displayUrl($base)];
        if ($base === '') {
            return HealthResult::fail($this->key(), (string) __('platform.health.gotenberg.not_configured'), $details);
        }

        $request = Http::timeout(2)->connectTimeout(2)->acceptJson();
        $username = config('laravel-pdf.gotenberg.username');
        if (is_string($username) && $username !== '') {
            $request = $request->withBasicAuth($username, (string) config('laravel-pdf.gotenberg.password', ''));
        }

        $response = $request->get($base.'/health');

        return $response->successful() && $response->json('status') === 'up'
            ? HealthResult::ok($this->key(), (string) __('platform.health.gotenberg.up'), $details)
            : HealthResult::fail($this->key(), (string) __('platform.health.gotenberg.down', ['status' => $response->status()]), $details);
    }

    private function displayUrl(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['host'])) {
            return '—';
        }

        return ($parts['scheme'] ?? 'http').'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
