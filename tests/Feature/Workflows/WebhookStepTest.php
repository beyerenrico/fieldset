<?php

declare(strict_types=1);

use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Workflows\Enums\RunStatus;
use App\Domain\Workflows\Models\WebhookEndpoint;
use App\Domain\Workflows\Webhooks\WebhookClient;
use App\Domain\Workflows\Webhooks\WebhookTarget;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Workflows\FakeHostResolver;
use Tests\Feature\Workflows\WorkflowFixtures as W;

/* M4-T02 webhook step: signed JSON, retries, SSRF guard (§8.3 AC: 127.0.0.1, 10.0.0.0/8, 169.254.169.254 refused). */

beforeEach(function (): void {
    W::notifier();
    $this->s = W::scenario();
    $this->resolver = FakeHostResolver::bind(['internal.example.test' => ['192.168.10.5'], 'mixed.example.test' => ['93.184.215.14', '10.0.0.7']]);
});

function hookTo(string $url): WebhookEndpoint
{
    $s = test()->s;
    $endpoint = app(TenantContext::class)->run($s->org, fn () => WebhookEndpoint::factory()->create(['url' => $url, 'secret' => 'shh-secret']));
    W::workflow($s->org, [W::step('webhook', ['endpoint_id' => $endpoint->id])]);

    return $endpoint;
}

it('posts the event with a submission summary and data, signed with HMAC-SHA256 over "{timestamp}.{body}"', function (): void {
    Http::fake(['hooks.example.com/*' => Http::response(['ok' => true], 202)]);
    hookTo('https://hooks.example.com/forms?x=1');

    $submission = W::submitted($this->s);

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) use ($submission): bool {
        $body = $request->body();
        $payload = json_decode($body, true);
        $timestamp = $request->header('X-Timestamp')[0];

        return $request->method() === 'POST' && $request->url() === 'https://hooks.example.com/forms?x=1'
            && $request->header('Content-Type')[0] === 'application/json'
            && $request->header('X-Signature')[0] === 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, 'shh-secret')
            && abs((int) $timestamp - time()) < 60
            && $payload['event'] === 'submission.submitted'
            && $payload['submission']['uuid'] === $submission->uuid
            && $payload['submission']['number'] === $submission->number
            && $payload['data']['customer_name'] === 'Beispiel GmbH'
            && $payload['data']['items'][0]['qty'] === '1.5'
            && $payload['form']['key'] === test()->s->form->key;
    });
    expect(W::runs($this->s->org)->sole()->status)->toBe(RunStatus::Completed);
});

dataset('ssrf targets', [
    'loopback' => ['http://127.0.0.1/hook'],
    'private 10/8' => ['http://10.1.2.3/hook'],
    'cloud metadata' => ['http://169.254.169.254/latest/meta-data/'],
    'IPv6 loopback' => ['http://[::1]/hook'],
    'IPv4-mapped IPv6' => ['http://[::ffff:127.0.0.1]/hook'],
    'CGNAT' => ['http://100.64.1.1/hook'],
    'host resolving to a private address' => ['https://internal.example.test/hook'],
    'host with one private address' => ['https://mixed.example.test/hook'],
]);

it('refuses webhooks to private, loopback and link-local addresses without sending anything', function (string $url): void {
    Http::fake();
    hookTo($url);

    W::submitted($this->s);

    $run = W::runs($this->s->org)->sole();
    Http::assertNothingSent();
    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->error)->toContain('not allowed');
})->with('ssrf targets');

it('allows private targets only when configured for development', function (): void {
    config(['workflows.webhooks.allow_private_hosts' => true]);
    Http::fake(['*' => Http::response('', 204)]);
    hookTo('https://internal.example.test/hook');

    W::submitted($this->s);

    Http::assertSentCount(1);
    expect(W::runs($this->s->org)->sole()->status)->toBe(RunStatus::Completed);
});

it('pins the connection to the validated address and never follows redirects', function (): void {
    $target = WebhookTarget::resolve('https://hooks.example.com:8443/in', $this->resolver);
    $literal = WebhookTarget::resolve('https://93.184.215.14/in', $this->resolver);

    expect($target->options())->toBe(['allow_redirects' => false, 'proxy' => '', 'curl' => [CURLOPT_RESOLVE => ['hooks.example.com:8443:93.184.215.14']]])
        ->and($literal->options())->toBe(['allow_redirects' => false, 'proxy' => '']);
});

it('fails at once on redirects and client errors, but retries server errors', function (int $status, bool $retried): void {
    Http::fake(['hooks.example.com/*' => Http::response('', $status, $status === 302 ? ['Location' => 'http://127.0.0.1/'] : [])]);
    hookTo('https://hooks.example.com/in');

    W::submitted($this->s);

    $step = W::runs($this->s->org)->sole()->stepRuns[0];
    expect($step->error)->toContain((string) $status)
        ->and(collect($step->log)->contains(fn (array $line): bool => $line['level'] === 'warning'))->toBe($retried);
    Http::assertSentCount(1);
})->with([
    'redirect' => [302, false],
    'not found' => [404, false],
    'too many requests' => [429, true],
    'server error' => [500, true],
]);

it('refuses URLs with credentials and non-http schemes', function (string $url): void {
    expect(WebhookTarget::syntaxProblem($url))->not->toBeNull();
})->with(['ftp://example.com/x', 'https://user:pass@example.com/x', 'http:///nohost', 'https://10.0.0.1/x']);

it('fails when the endpoint is disabled', function (): void {
    Http::fake();
    $endpoint = hookTo('https://hooks.example.com/in');
    app(TenantContext::class)->run($this->s->org, fn () => $endpoint->forceFill(['active' => false])->save());

    W::submitted($this->s);

    Http::assertNothingSent();
    expect(W::runs($this->s->org)->sole()->error)->toContain($endpoint->name);
});

it('computes the documented signature', function (): void {
    expect(WebhookClient::signature('k', '1700000000', '{"a":1}'))->toBe('sha256='.hash_hmac('sha256', '1700000000.{"a":1}', 'k'));
});
