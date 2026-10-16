<?php

declare(strict_types=1);

use App\Domain\Audit\ErrorText;

/* Stored error messages are scrubbed and truncated (M3 review #8); users only ever see fixed texts. */

it('removes credentials from URLs, key=value secrets and given secrets', function (): void {
    $text = ErrorText::scrub(
        'Connection to smtps://mailer:hunter2@smtp.example.com:465 failed; password=hunter2&token=abc123 api_key: XYZ s3cr3t',
        ['s3cr3t'],
    );

    expect($text)->toBe('Connection to smtps://[redacted]@smtp.example.com:465 failed; password=[redacted]&token=[redacted] api_key: [redacted] [redacted]')
        ->not->toContain('hunter2');
});

it('drops the application path, control characters and collapses whitespace', function (): void {
    $text = ErrorText::scrub('Unable to open '.base_path('storage/app/x.pdf')."\n\tat line\x00 3");

    expect($text)->toBe('Unable to open storage/app/x.pdf at line 3');
});

it('truncates long messages', function (): void {
    $text = ErrorText::scrub(str_repeat('a', 2000));

    expect(mb_strlen($text))->toBe(ErrorText::LIMIT)->and($text)->toEndWith('…');
});

it('returns a placeholder for empty input', function (): void {
    expect(ErrorText::scrub("  \n "))->toBe('—');
});
