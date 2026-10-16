<?php

declare(strict_types=1);

namespace App\Domain\Documents\Rendering;

use App\Domain\Documents\Branding\Branding;
use App\Domain\Documents\Branding\FontLibrary;
use Illuminate\Support\Traits\Localizable;

/**
 * Renders the canonical input into the body, header and footer HTML with the app-owned Blade views
 * (resources/views/documents). Deterministic: the same input always yields the same HTML.
 */
final readonly class HtmlComposer
{
    use Localizable;

    public function __construct(
        private AssetInliner $assets,
        private RichTextSanitizer $sanitizer,
    ) {}

    public function compose(RenderInput $input): ComposedHtml
    {
        return $this->withLocale($input->language(), fn (): ComposedHtml => $this->render($input));
    }

    private function render(RenderInput $input): ComposedHtml
    {
        $design = $input->design;
        $format = new ValueFormatter($input->locale(), $input->meta->timezone, $design->dateStyle, $input->language());
        $tree = new DocumentTree($input, $format, $this->assets, $this->sanitizer)->build();
        $tokens = $this->tokens($input, $format);
        $shared = [
            'lang' => $input->languageTag(),
            'fontFamily' => FontLibrary::family($input->branding->font),
            'margins' => $design->margins,
            'primary' => $input->branding->primaryColor,
            'accent' => $input->branding->accentColor,
            'primaryText' => $input->branding->primaryText(),
            'accentText' => $input->branding->accentText(),
            'onPrimary' => $input->branding->onPrimary(),
        ];
        $hfFont = FontLibrary::css($input->branding->font, [400, 700]);

        $body = view('documents.document', [...$shared,
            'fontCss' => FontLibrary::css($input->branding->font),
            'design' => $design->toArray(),
            'title' => DocumentTree::text($input->definition->title ?? $input->meta->formTitle),
            'metaLines' => $this->metaLines($input, $tokens),
            'pages' => $tree['pages'],
            'signatures' => $tree['signatures'],
            'watermark' => $this->watermark($input),
        ])->render();
        $header = view('documents.header', [...$shared,
            'fontCss' => $hfFont,
            'logo' => $design->showLogo ? $this->assets->logo($input->branding, $input->meta->organizationId) : null,
            'html' => TokenReplacer::replace($this->sanitizer->sanitize(TokenReplacer::fromMergeTags($design->header)), $tokens),
        ])->render();
        $footer = view('documents.footer', [...$shared,
            'fontCss' => $hfFont,
            'html' => TokenReplacer::replace($this->sanitizer->sanitize(TokenReplacer::fromMergeTags($design->footer)), $tokens),
            'legal' => $this->legalLine($input->branding),
        ])->render();

        return new ComposedHtml($body, $header, $footer);
    }

    /** @return array<string, string> */
    private function tokens(RenderInput $input, ValueFormatter $format): array
    {
        $submitted = $input->meta->submittedAt;

        return [
            'org.name' => $input->meta->organizationName,
            'submission.number' => $input->meta->number ?? (string) __('documents.render.number_pending'),
            // submittedAt is RFC 3339 in the organization time zone, so its date part is the local date.
            'submission.date' => $submitted === null ? '' : $format->date(substr($submitted, 0, 10)),
        ];
    }

    /**
     * @param  array<string, string>  $tokens
     * @return array<string, string>
     */
    private function metaLines(RenderInput $input, array $tokens): array
    {
        $lines = [(string) __('documents.render.number') => $tokens['submission.number']];
        if ($tokens['submission.date'] !== '') {
            $lines[(string) __('documents.render.date')] = $tokens['submission.date'];
        }
        if ($input->meta->formVersion !== null) {
            $lines[(string) __('documents.render.version')] = 'v'.$input->meta->formVersion;
        }

        return $lines;
    }

    private function watermark(RenderInput $input): ?string
    {
        $mark = match ($input->design->watermark) {
            'draft' => 'draft',
            'void' => 'void',
            'auto' => $input->meta->statusMark(),
            default => null,
        };

        return $mark === null ? null : (string) __('documents.render.watermark_'.$mark);
    }

    private function legalLine(Branding $branding): string
    {
        $company = $branding->company;
        if (isset($company['legal_footer'])) {
            return $company['legal_footer'];
        }
        $parts = [
            $branding->companyName,
            isset($company['address']) ? (string) preg_replace('/\s*\R\s*/', ', ', $company['address']) : null,
            $company['vat_id'] ?? $company['tax_id'] ?? null,
            isset($company['iban']) ? 'IBAN '.$company['iban'].(isset($company['bic']) ? ' · BIC '.$company['bic'] : '') : null,
        ];

        return implode(' · ', array_filter($parts, static fn (?string $p): bool => $p !== null && $p !== ''));
    }
}
