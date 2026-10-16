<?php

declare(strict_types=1);

use App\Domain\Documents\Rendering\RichTextSanitizer;
use App\Domain\Documents\Rendering\TokenReplacer;

it('replaces the five tokens with escaped values and page placeholders', function (): void {
    $html = TokenReplacer::replace('<p>{{org.name}} · {{ submission.number }} · {{submission.date}} · {{page}}/{{pages}}</p>', [
        'org.name' => 'Müller & Söhne <GmbH>', 'submission.number' => 'SR-2026-00001', 'submission.date' => '16.10.2026',
    ]);

    expect($html)->toBe('<p>Müller &amp; Söhne &lt;GmbH&gt; · SR-2026-00001 · 16.10.2026 · <span class="pageNumber"></span>/<span class="totalPages"></span></p>');
});

it('never executes or re-expands values and leaves unknown tokens as text', function (): void {
    $html = TokenReplacer::replace('<p>{{org.name}} {{user.password}} {{ $x }}</p>', ['org.name' => '{{page}}<script>alert(1)</script>']);

    expect($html)->toBe('<p>{{page}}&lt;script&gt;alert(1)&lt;/script&gt; {{user.password}} {{ $x }}</p>')
        ->and($html)->not->toContain('pageNumber');
});

it('turns rich-editor merge tags into tokens', function (): void {
    $html = TokenReplacer::replace('<p>Nr. <span data-type="mergeTag" data-id="submission.number"></span></p>', ['submission.number' => 'A-1']);

    expect($html)->toBe('<p>Nr. A-1</p>');
});

it('sanitizes tenant rich text to an allow-list', function (): void {
    $sanitizer = new RichTextSanitizer;
    $html = $sanitizer->sanitize('<p style="text-align: center; background: url(http://evil)">Hi <strong>there</strong><img src="http://evil/x.png"><script>alert(1)</script><a href="javascript:x">link</a></p><style>body{}</style><iframe src="http://x"></iframe>');

    expect($html)->toBe('<p style="text-align: center">Hi <strong>there</strong>link</p>')
        ->and($sanitizer->markdown("# Title\n\n**bold** <b onclick=x>raw</b> [l](javascript:alert(1)) ![i](http://evil/i.png)"))
        ->not->toContain('<img')->not->toContain('<b onclick')->not->toContain('href')->toContain('<strong>bold</strong>')->toContain('&lt;b onclick');
});
