<?php

declare(strict_types=1);

use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Tests\Feature\Documents\DocumentFixtures;

/*
 * The app side of the install smoke test (R11, KTD25; ADR 0072 amendment), as the socket-less one-offs fsa runs:
 * `instance:pdf-test` renders one page through the app's Gotenberg client, `instance:mail-test --install` sends the
 * install's test mail with a Message-ID of its own (fsa looks it up in the catcher) and wording for capture mode or
 * delivery.
 */

/** @return array{0: array<string, mixed>, 1: int} */
function smokeCommandJson(string $command, array $arguments = []): array
{
    $exit = Artisan::call($command, [...$arguments, '--json' => true]);
    $lines = array_values(array_filter(explode("\n", trim(Artisan::output()))));
    expect($lines)->toHaveCount(1);

    return [json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR), $exit];
}

/** @return list<SentMessage> */
function smokeSentMails(): array
{
    $transport = Mail::mailer('array')->getSymfonyTransport();

    return $transport instanceof ArrayTransport ? array_values($transport->messages()->all()) : [];
}

it('renders a one-page test PDF through Gotenberg', function (): void {
    Http::fake([DocumentFixtures::GOTENBERG_ROUTE => Http::response(DocumentFixtures::pdf(), 200, ['Content-Type' => 'application/pdf'])]);

    [$json, $exit] = smokeCommandJson('instance:pdf-test');

    expect($exit)->toBe(0)
        ->and($json['status'])->toBe('ok')
        ->and($json['data']['bytes'])->toBeGreaterThan(100);
    Http::assertSent(static fn ($request): bool => str_ends_with($request->url(), '/forms/chromium/convert/html')
        && str_contains(json_encode($request->data()) ?: '', 'Install smoke test'));
});

it('reports pdf_render_failed when Gotenberg fails or answers with something else than a PDF', function (int $status, string $body, string $message): void {
    Http::fake([DocumentFixtures::GOTENBERG_ROUTE => Http::response($body, $status)]);

    [$json, $exit] = smokeCommandJson('instance:pdf-test');

    expect($exit)->toBe(1)
        ->and($json['errors'][0])->toMatchArray(['code' => 'pdf_render_failed', 'retryable' => true])
        ->and($json['errors'][0]['message'])->toContain($message);
})->with([
    'Gotenberg unavailable' => [503, 'unavailable', 'did not render the test PDF'],
    'not a PDF' => [200, '<html>proxy error</html>', 'not with a PDF'],
]);

it('sends the install test mail with its own Message-ID and the wording for capture mode or delivery', function (bool $capture, string $wording): void {
    config(['mail.default' => 'array', 'mail.capture' => $capture, 'mail.from.address' => 'forms@example.com']);

    [$json, $exit] = smokeCommandJson('instance:mail-test', ['--to' => 'Owner@Example.com', '--install' => true]);

    $sent = smokeSentMails();
    $email = ($sent[0] ?? null)?->getOriginalMessage();
    expect($exit)->toBe(0)
        ->and($json['data']['to'])->toBe('owner@example.com')
        ->and($json['data']['message_id'])->toMatch('/^[0-9a-f]{32}@example\.com$/')
        ->and($sent)->toHaveCount(1)
        ->and($email)->toBeInstanceOf(Email::class)
        ->and($email->getHeaders()->get('Message-ID')?->getBodyAsString())->toBe('<'.$json['data']['message_id'].'>')
        ->and($email->getSubject())->toBe(__('capture.test_mail.subject'))
        ->and($email->getTextBody())->toBe(__($wording, ['url' => config('app.url')]));
})->with([
    'capture mode' => [true, 'capture.test_mail.install_capture'],
    'SMTP' => [false, 'capture.test_mail.install_smtp'],
]);

it('keeps the config set mail wording and output without --install', function (): void {
    config(['mail.default' => 'array', 'mail.capture' => true]);

    [$json] = smokeCommandJson('instance:mail-test', ['--to' => 'ops@example.com']);

    $email = smokeSentMails()[0]->getOriginalMessage();
    expect($json['data'])->toBe(['to' => 'ops@example.com', 'mailer' => 'array'])
        ->and($email)->toBeInstanceOf(Email::class)
        ->and($email->getTextBody())->toBe(__('capture.test_mail.body', ['url' => config('app.url')]));
});
