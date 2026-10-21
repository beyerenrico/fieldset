<?php

declare(strict_types=1);

namespace Fieldset\Definition;

/**
 * Element type vocabulary (spec §3.2).
 */
final class ElementType
{
    public const LAYOUT = ['section', 'columns', 'heading', 'paragraph', 'divider', 'image', 'page_break'];

    public const DATA = [
        'text', 'textarea', 'email', 'phone', 'number', 'money', 'date', 'time', 'datetime',
        'select', 'radio', 'multiselect', 'checkbox', 'toggle', 'rating', 'photo', 'file',
        'signature', 'location', 'calculated', 'hidden', 'repeater',
    ];

    public const RESERVED = ['record', 'barcode', 'rich_text', 'lookup'];

    /** Types whose values are decimal strings. */
    public const DECIMAL = ['number', 'money'];

    public const WITH_OPTIONS = ['select', 'radio', 'multiselect'];

    public static function isLayout(string $type): bool
    {
        return in_array($type, self::LAYOUT, true);
    }

    /**
     * Every non-layout type carries data, including unknown and reserved types (they are
     * reported by the meta-schema and the linter, but their keys still count as data keys).
     */
    public static function isData(string $type): bool
    {
        return $type !== '' && ! self::isLayout($type);
    }

    public static function isReserved(string $type): bool
    {
        return in_array($type, self::RESERVED, true);
    }

    public static function isKnown(string $type): bool
    {
        return in_array($type, self::DATA, true) || self::isLayout($type);
    }
}
