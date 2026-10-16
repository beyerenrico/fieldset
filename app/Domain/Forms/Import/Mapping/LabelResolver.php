<?php

declare(strict_types=1);

namespace App\Domain\Forms\Import\Mapping;

use App\Domain\Forms\Import\Model\AcroField;
use App\Domain\Forms\Import\Model\TextRun;

/**
 * Labels per §8.13: the tooltip (/TU), else the nearest page text to the left of or above the field,
 * else the humanized field name. Deterministic: ties prefer text on the left, then the leftmost run.
 */
final class LabelResolver
{
    public const string TOOLTIP = 'tooltip';

    public const string TEXT = 'text';

    public const string NAME = 'name';

    private const float MAX_LEFT_GAP = 150.0;

    private const float MAX_ABOVE_GAP = 30.0;

    /** Confidence cap per label source. */
    public const array CONFIDENCE = [self::TOOLTIP => 1.0, self::TEXT => 0.8, self::NAME => 0.5];

    /**
     * @param  list<TextRun>  $texts  runs on the field's page
     * @return array{label: string, source: string}
     */
    public function resolve(AcroField $field, array $texts, string $fallbackName, ?string $tooltip = null): array
    {
        $tooltip = $this->tidy($tooltip ?? $field->tooltip ?? '');
        if ($tooltip !== '') {
            return ['label' => $tooltip, 'source' => self::TOOLTIP];
        }

        $nearby = $this->nearestText($field->rect, $texts);
        if ($nearby !== null) {
            return ['label' => $nearby, 'source' => self::TEXT];
        }

        return ['label' => self::humanize($fallbackName), 'source' => self::NAME];
    }

    /**
     * @param  array{0: float, 1: float, 2: float, 3: float}  $rect
     * @param  list<TextRun>  $texts
     */
    public function nearestText(array $rect, array $texts, float $maxAbove = self::MAX_ABOVE_GAP): ?string
    {
        [$x1, $y1, $x2, $y2] = $rect;
        $best = null;
        foreach ($texts as $run) {
            $distance = $this->leftDistance($run, $x1, $y1, $y2);
            $side = 0;
            if ($distance === null) {
                $distance = $this->aboveDistance($run, $x1, $x2, $y2, $maxAbove);
                $side = 1;
            }
            if ($distance === null) {
                continue;
            }
            $score = [$distance, $side, $run->x, $run->y * -1];
            if ($best === null || $score < $best[0]) {
                $best = [$score, $run->text];
            }
        }

        if ($best === null) {
            return null;
        }
        $label = $this->tidy($best[1]);

        return $label === '' ? null : $label;
    }

    private function leftDistance(TextRun $run, float $x1, float $y1, float $y2): ?float
    {
        $gap = $x1 - $run->right();
        $center = $run->centerY();
        $slack = max(2.0, $run->size * 0.5);
        if ($gap < -4 || $gap > self::MAX_LEFT_GAP || $center < $y1 - $slack || $center > $y2 + $slack) {
            return null;
        }

        return max($gap, 0.0);
    }

    private function aboveDistance(TextRun $run, float $x1, float $x2, float $y2, float $maxAbove): ?float
    {
        $gap = $run->y - $y2;
        if ($gap < -2 || $gap > $maxAbove || $run->x < $x1 - 20 || $run->x > $x2) {
            return null;
        }

        return max($gap, 0.0);
    }

    /** "CustomerName" / "order_no" / "items.desc" → "Customer name" / "Order no" / "Items desc". */
    public static function humanize(string $name): string
    {
        $words = (string) preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $name);
        $words = (string) preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1 $2', $words);
        $words = trim((string) preg_replace('/[\s_.\-]+/u', ' ', $words));
        $words = mb_strtolower($words);

        return $words === '' ? 'Field' : mb_strtoupper(mb_substr($words, 0, 1)).mb_substr($words, 1);
    }

    private function tidy(string $label): string
    {
        $label = trim((string) preg_replace('/\s+/u', ' ', $label));

        return mb_substr((string) preg_replace('/[\s:*\x{2026}]+$/u', '', $label), 0, 200);
    }
}
