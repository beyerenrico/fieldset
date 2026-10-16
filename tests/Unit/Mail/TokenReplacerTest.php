<?php

declare(strict_types=1);

use App\Domain\Mail\Templates\MailTemplate;
use App\Domain\Mail\Templates\MarkdownRenderer;
use App\Domain\Mail\Templates\TokenReplacer;

beforeEach(function (): void {
    $this->replacer = new TokenReplacer;
    $this->html = fn (string $template, array $tokens): string => (new MarkdownRenderer)->toHtml($this->replacer->markdown($template, $tokens));
});

it('replaces known tokens, tolerates spacing and case, and leaves unknown tokens as text', function (): void {
    $result = $this->replacer->plain('Hi {{ recipient.name }}, {{ORG.NAME}} sent {{submission.number}} {{unknown.token}} {{ not a token }}', [
        'recipient.name' => 'Ann', 'org.name' => 'Acme', 'submission.number' => 'LS-7',
    ]);

    expect($result)->toBe('Hi Ann, Acme sent LS-7 {{unknown.token}} {{ not a token }}');
});

it('never executes or re-expands values (single pass, no Blade)', function (): void {
    $result = $this->replacer->plain('{{a}} {{b}}', ['a' => '{{b}}', 'b' => '{{ 1 + 1 }} @php echo 1; @endphp <?php echo 2; ?>']);

    expect($result)->toBe('{{b}} {{ 1 + 1 }} @php echo 1; @endphp <?php echo 2; ?>');
});

it('reduces plain values to one line without control characters (no header injection)', function (): void {
    expect($this->replacer->plain('Subject {{x}}', ['x' => "evil\r\nBcc: victim@example.com\x07"]))
        ->toBe('Subject evil Bcc: victim@example.com');
});

it('escapes values in Markdown bodies so they render as literal text', function (string $value, string $expectedFragment, string $forbidden): void {
    $html = ($this->html)('Customer: {{name}}', ['name' => $value]);

    expect($html)->toContain($expectedFragment)->not->toContain($forbidden);
})->with([
    'html' => ['<script>alert(1)</script>', '&lt;script&gt;alert(1)&lt;/script&gt;', '<script>'],
    'markdown link' => ['[click](https://evil.example)', '[click](https://evil.example)', '<a '],
    'image' => ['![x](https://evil.example/x.png)', '![x]', '<img'],
    'emphasis' => ['**bold** _it_', '**bold** _it_', '<strong>'],
    'heading via newline' => ["x\n# Heading", '# Heading', '<h1>'],
    'entity' => ['Smith & Sons "Ltd" \'x\'', 'Smith &amp; Sons &quot;Ltd&quot; \'x\'', '&amp;amp;'],
    'autolink' => ['<javascript:alert(1)>', '&lt;javascript:alert(1)&gt;', 'href'],
]);

it('keeps the template Markdown but escapes raw HTML and drops unsafe links', function (): void {
    $html = ($this->html)("# Hello {{name}}\n\n**Thanks** [site](https://example.com) [x](javascript:alert(1)) <b>raw</b>", ['name' => 'Ann']);

    expect($html)->toContain('<h1>Hello Ann</h1>')
        ->toContain('<strong>Thanks</strong>')
        ->toContain('<a href="https://example.com">site</a>')
        ->toContain('&lt;b&gt;raw&lt;/b&gt;')
        ->not->toContain('javascript:');
});

it('escapes values inside link destinations without breaking the URL', function (): void {
    $html = ($this->html)('[Download]({{document.link}})', ['document.link' => 'https://app.example/documents/links/abc_DEF-1?x=1&y=2']);

    expect($html)->toContain('<a href="https://app.example/documents/links/abc_DEF-1?x=1&amp;y=2">Download</a>');
});

it('builds templates from stored step configuration and rejects oversized ones', function (): void {
    expect(MailTemplate::fromArray(['subject' => 'S', 'body' => 'B', 'extra' => 1])->toArray())->toBe(['subject' => 'S', 'body' => 'B'])
        ->and(MailTemplate::fromArray(['subject' => ['x']])->subject)->toBe('')
        ->and(fn (): MailTemplate => new MailTemplate(str_repeat('x', 256), ''))->toThrow(InvalidArgumentException::class);
});
