<?php

declare(strict_types=1);

namespace App\Domain\Forms\Builder;

use App\Domain\Forms\Builder\FieldSpec as F;

/**
 * Which definition properties each element type edits in Builder v1. Everything else is carried in
 * the block's `_source` and written back untouched (lossless mapping, BUILD_PROMPT §8.2).
 */
final class ElementFields
{
    public const array INPUT_TYPES = ['text', 'textarea', 'email', 'phone', 'number', 'money', 'date', 'time', 'datetime',
        'select', 'multiselect', 'radio', 'checkbox', 'toggle', 'rating'];

    public const array CAPTURE_TYPES = ['photo', 'file', 'signature', 'location'];

    public const array LAYOUT_TYPES = ['section', 'columns', 'heading', 'paragraph', 'divider', 'image', 'page_break'];

    public const array ALL_TYPES = [...self::INPUT_TYPES, ...self::CAPTURE_TYPES, 'calculated', 'hidden', 'repeater', ...self::LAYOUT_TYPES];

    /** @return list<F> */
    public static function for(string $type): array
    {
        return match (true) {
            in_array($type, self::INPUT_TYPES, true), in_array($type, self::CAPTURE_TYPES, true) => [...self::data($type), ...self::extra($type)],
            $type === 'calculated' => [...self::presentation(), self::f('role', 'role'), ...self::pdf(), ...self::extra($type)],
            $type === 'hidden' => [self::f('label', 'label'), new F('default', ['default'], F::DEFAULT), self::f('role', 'role')],
            $type === 'repeater' => [...self::presentation(), self::bool('required', 'required'), ...self::pdf(), ...self::extra($type)],
            default => [new F('visible_if', ['visibleIf'], F::LOGIC), ...self::extra($type)],
        };
    }

    /** @return list<F> */
    private static function data(string $type): array
    {
        $defaultKind = match ($type) {
            'checkbox', 'toggle' => F::DEFAULT_BOOL,
            'rating' => F::DEFAULT_INT,
            default => F::DEFAULT,
        };

        return [
            ...self::presentation(),
            self::f('placeholder', 'placeholder'),
            self::bool('required', 'required'),
            new F('required_if', ['requiredIf'], F::LOGIC),
            new F('read_only_if', ['readOnlyIf'], F::LOGIC),
            new F('default', ['default'], $defaultKind),
            self::f('role', 'role'),
            ...self::pdf(),
        ];
    }

    /** @return list<F> */
    private static function presentation(): array
    {
        return [self::f('label', 'label'), self::f('help', 'help'), new F('visible_if', ['visibleIf'], F::LOGIC)];
    }

    /** @return list<F> */
    private static function pdf(): array
    {
        return [new F('pdf_hidden', ['pdf', 'hidden'], F::BOOL), new F('pdf_keep_together', ['pdf', 'keepTogether'], F::BOOL)];
    }

    /** @return list<F> */
    private static function extra(string $type): array
    {
        $length = [new F('v_min_length', ['validation', 'minLength'], F::INT), new F('v_max_length', ['validation', 'maxLength'], F::INT)];
        $pattern = [self::f('v_pattern', 'validation', 'pattern')];
        $bounds = [new F('v_min', ['validation', 'min'], F::NUMERIC), new F('v_max', ['validation', 'max'], F::NUMERIC)];
        $step = [new F('v_step', ['validation', 'step'], F::NUMERIC)];
        $files = [self::bool('multiple', 'multiple'), new F('v_max_files', ['validation', 'maxFiles'], F::INT),
            new F('v_max_size_kb', ['validation', 'maxSizeKb'], F::INT), new F('v_accept', ['validation', 'accept'], F::LIST)];
        $options = [new F('options', ['options'], F::OPTIONS)];

        return match ($type) {
            'text', 'phone' => [...$length, ...$pattern],
            'textarea' => [...$length, ...$pattern, new F('rows', ['rows'], F::INT)],
            'email' => $length,
            'number' => [...$bounds, ...$step],
            'money' => [self::f('currency', 'currency'), new F('scale', ['scale'], F::INT), ...$bounds, ...$step],
            'date', 'time', 'datetime' => $bounds,
            'select', 'radio' => $options,
            'multiselect' => [...$options, ...$bounds],
            'rating' => [new F('max', ['max'], F::INT)],
            'photo', 'file' => $files,
            'signature' => [self::f('signer_name_from', 'signerNameFrom'), new F('v_max_size_kb', ['validation', 'maxSizeKb'], F::INT)],
            'calculated' => [new F('expression', ['expression'], F::CALC), self::f('format', 'format'), new F('scale', ['scale'], F::INT), self::f('currency', 'currency')],
            'repeater' => [self::f('display', 'display'), new F('min_items', ['minItems'], F::INT), new F('max_items', ['maxItems'], F::INT),
                self::f('add_label', 'addLabel'), self::f('item_label', 'itemLabel')],
            'section' => [self::f('label', 'label'), self::bool('collapsible', 'collapsible')],
            'heading' => [self::f('text', 'text'), new F('level', ['level'], F::INT)],
            'paragraph' => [self::f('text', 'text')],
            'image' => [self::f('src', 'src'), self::f('alt', 'alt')],
            default => [],
        };
    }

    private static function f(string $ui, string ...$path): F
    {
        return new F($ui, array_values($path), F::STRING);
    }

    private static function bool(string $ui, string ...$path): F
    {
        return new F($ui, array_values($path), F::BOOL);
    }
}
