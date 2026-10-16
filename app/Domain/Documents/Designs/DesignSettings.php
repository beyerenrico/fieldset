<?php

declare(strict_types=1);

namespace App\Domain\Documents\Designs;

/**
 * Settings of a document design (BUILD_PROMPT §8.5): paper, orientation, margins, header/footer rich
 * text with tokens, layout style, repeater style, photo grid size, empty fields, signature placement,
 * watermark and locale formats. Unknown keys are dropped and invalid values fall back to defaults, so
 * stored settings always render.
 */
final readonly class DesignSettings
{
    public const array PAPERS = ['a4', 'letter'];

    public const array ORIENTATIONS = ['portrait', 'landscape'];

    public const array LAYOUTS = ['two_column', 'compact'];

    public const array REPEATER_STYLES = ['auto', 'table', 'cards'];

    public const array SIGNATURE_PLACEMENTS = ['inline', 'end'];

    public const array WATERMARKS = ['auto', 'none', 'draft', 'void'];

    public const array DATE_STYLES = ['short', 'medium', 'long'];

    public const array MARGIN_SIDES = ['top', 'right', 'bottom', 'left'];

    public const int MARGIN_MIN = 5;

    public const int MARGIN_MAX = 60;

    public const int HTML_MAX = 5000;

    private const array DEFAULT_MARGINS = ['top' => 28, 'right' => 18, 'bottom' => 24, 'left' => 18];

    private const string DEFAULT_HEADER = '<p><strong>{{org.name}}</strong></p>';

    private const string DEFAULT_FOOTER = '<p>{{submission.number}} · {{submission.date}} · {{page}} / {{pages}}</p>';

    /** @param  array{top: int, right: int, bottom: int, left: int}  $margins  millimetres */
    public function __construct(
        public string $paper,
        public string $orientation,
        public array $margins,
        public string $header,
        public string $footer,
        public bool $showLogo,
        public string $layout,
        public string $repeaters,
        public int $photoColumns,
        public bool $showEmpty,
        public string $signaturePlacement,
        public string $watermark,
        public ?string $locale,
        public string $dateStyle,
    ) {}

    public static function defaults(): self
    {
        return self::fromArray([]);
    }

    /** @param  array<array-key, mixed>  $data */
    public static function fromArray(array $data): self
    {
        $margins = is_array($data['margins'] ?? null) ? $data['margins'] : [];

        return new self(
            self::choice($data['paper'] ?? null, self::PAPERS),
            self::choice($data['orientation'] ?? null, self::ORIENTATIONS),
            self::margins($margins),
            self::html($data['header'] ?? null, self::DEFAULT_HEADER),
            self::html($data['footer'] ?? null, self::DEFAULT_FOOTER),
            self::bool($data['show_logo'] ?? null, true),
            self::choice($data['layout'] ?? null, self::LAYOUTS),
            self::choice($data['repeaters'] ?? null, self::REPEATER_STYLES),
            self::int($data['photo_columns'] ?? null, 3, 1, 4),
            self::bool($data['show_empty'] ?? null, false),
            self::choice($data['signature_placement'] ?? null, self::SIGNATURE_PLACEMENTS),
            self::choice($data['watermark'] ?? null, self::WATERMARKS),
            self::locale($data['locale'] ?? null),
            self::choice($data['date_style'] ?? null, self::DATE_STYLES, 'medium'),
        );
    }

    /** @return array<string, mixed> canonical form, the shape stored in designs and design_versions */
    public function toArray(): array
    {
        return [
            'paper' => $this->paper,
            'orientation' => $this->orientation,
            'margins' => $this->margins,
            'header' => $this->header,
            'footer' => $this->footer,
            'show_logo' => $this->showLogo,
            'layout' => $this->layout,
            'repeaters' => $this->repeaters,
            'photo_columns' => $this->photoColumns,
            'show_empty' => $this->showEmpty,
            'signature_placement' => $this->signaturePlacement,
            'watermark' => $this->watermark,
            'locale' => $this->locale,
            'date_style' => $this->dateStyle,
        ];
    }

    /** @param  list<string>  $allowed */
    private static function choice(mixed $value, array $allowed, ?string $default = null): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : ($default ?? $allowed[0]);
    }

    /**
     * @param  array<array-key, mixed>  $margins
     * @return array{top: int, right: int, bottom: int, left: int}
     */
    private static function margins(array $margins): array
    {
        return [
            'top' => self::int($margins['top'] ?? null, self::DEFAULT_MARGINS['top'], self::MARGIN_MIN, self::MARGIN_MAX),
            'right' => self::int($margins['right'] ?? null, self::DEFAULT_MARGINS['right'], self::MARGIN_MIN, self::MARGIN_MAX),
            'bottom' => self::int($margins['bottom'] ?? null, self::DEFAULT_MARGINS['bottom'], self::MARGIN_MIN, self::MARGIN_MAX),
            'left' => self::int($margins['left'] ?? null, self::DEFAULT_MARGINS['left'], self::MARGIN_MIN, self::MARGIN_MAX),
        ];
    }

    private static function int(mixed $value, int $default, int $min, int $max): int
    {
        $int = is_int($value) ? $value : (is_string($value) && ctype_digit($value) ? (int) $value : null);

        return $int === null ? $default : max($min, min($max, $int));
    }

    private static function bool(mixed $value, bool $default): bool
    {
        return is_bool($value) ? $value : (in_array($value, [0, 1, '0', '1'], true) ? (bool) $value : $default);
    }

    private static function html(mixed $value, string $default): string
    {
        return is_string($value) ? mb_substr($value, 0, self::HTML_MAX) : $default;
    }

    private static function locale(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[a-z]{2,3}(-[A-Z]{2})?$/', $value) === 1 ? $value : null;
    }
}
