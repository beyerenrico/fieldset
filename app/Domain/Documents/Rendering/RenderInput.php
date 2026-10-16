<?php

declare(strict_types=1);

namespace App\Domain\Documents\Rendering;

use App\Domain\Documents\Branding\Branding;
use App\Domain\Documents\Designs\DesignSettings;
use stdClass;

/**
 * The canonical render input (§8.5): definition, evaluated data and visibility, branding, the design
 * version's settings, document metadata and the inlined assets. Rendering is a pure function of it.
 */
final readonly class RenderInput
{
    public const string FALLBACK_LOCALE = 'en-US';

    /**
     * @param  array<string, bool>  $visibility  scope pointer => visible (spec §5.5)
     * @param  array<string, RenderAsset>  $assets  attachment uuid (lowercase) => asset
     */
    public function __construct(
        public stdClass $definition,
        public stdClass $data,
        public array $visibility,
        public Branding $branding,
        public DesignSettings $design,
        public DocumentMeta $meta,
        public array $assets = [],
    ) {}

    /** BCP 47 locale for numbers and dates: the design's override, else the definition's. */
    public function locale(): string
    {
        $locale = $this->design->locale ?? ($this->definition->locale ?? null);

        return is_string($locale) && $locale !== '' ? $locale : self::FALLBACK_LOCALE;
    }

    /** BCP 47 tag for `<html lang>` (e.g. de-DE, de-AT), so assistive technology and hyphenation use the right variant. */
    public function languageTag(): string
    {
        $tag = str_replace('_', '-', $this->locale());

        return preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/', $tag) === 1 ? $tag : self::FALLBACK_LOCALE;
    }

    /** UI language of fixed document strings (yes/no, page labels): de or en (D7). */
    public function language(): string
    {
        return str_starts_with(strtolower($this->locale()), 'de') ? 'de' : 'en';
    }

    public function asset(mixed $uuid): ?RenderAsset
    {
        return is_string($uuid) ? ($this->assets[strtolower($uuid)] ?? null) : null;
    }

    /** @return array<string, mixed> everything except asset bytes (their sha256 stands in) */
    public function canonical(): array
    {
        $assets = array_map(static fn (RenderAsset $a): array => ['kind' => $a->kind, 'mime' => $a->mime, 'sha256' => $a->sha256], $this->assets);
        ksort($assets);

        return [
            'definition' => $this->definition,
            'data' => $this->data,
            'visibility' => $this->visibility,
            'branding' => $this->branding->toArray(),
            'design' => $this->design->toArray(),
            'meta' => $this->meta->toArray(),
            'assets' => $assets,
        ];
    }
}
