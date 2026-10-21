<?php

declare(strict_types=1);

namespace Fieldset\Evaluation;

use Fieldset\Definition\Element;
use Fieldset\Json\JsNumber;
use Fieldset\Logic\DateValue;
use Fieldset\Logic\Decimal;
use Fieldset\Support\EcmaRegex;
use Fieldset\Support\Text;

/**
 * Type checks of §5.6 (codes `type_mismatch` … `invalid_location`). A value failing them is
 * `null` in the logic view.
 */
final class TypeChecker
{
    public const UUID = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/D';

    /** Email shape, matched with the §3.4 semantics (JavaScript whitespace, `$` end-only). */
    public const EMAIL = '^[^\s@]+@[^\s@]+\.[^\s@]+$';

    public const EMAIL_MAX_LENGTH = 254;

    public const PHONE = '^\+?[0-9 ()./-]{3,32}$';

    public static function check(Element $element, mixed $value): ?Violation
    {
        if ($value === null) {
            return null;
        }

        return match ($element->type) {
            'text', 'textarea' => is_string($value) ? null : self::mismatch(),
            'email' => self::matches($value, self::EMAIL, 'invalid_email', self::EMAIL_MAX_LENGTH),
            'phone' => self::matches($value, self::PHONE, 'invalid_phone', EcmaRegex::MAX_SUBJECT_LENGTH),
            'number', 'money' => self::decimal($element, $value),
            'date' => self::valid($value, DateValue::parseDate($value) !== null, 'invalid_date'),
            'time' => self::valid($value, DateValue::parseTime($value) !== null, 'invalid_time'),
            'datetime' => self::valid($value, DateValue::parseDateTime($value) !== null, 'invalid_datetime'),
            'select', 'radio' => self::option($element, $value),
            'multiselect' => self::options($element, $value),
            'checkbox', 'toggle' => is_bool($value) ? null : self::mismatch(),
            'photo', 'file' => self::attachments($element, $value),
            'signature' => SignatureLocation::checkSignature($value),
            'location' => SignatureLocation::checkLocation($value),
            'hidden' => self::isHiddenValue($value) ? null : self::mismatch(),
            'repeater' => is_array($value) && array_is_list($value) ? null : self::mismatch(),
            default => null,
        };
    }

    public static function passes(Element $element, mixed $value): bool
    {
        return self::check($element, $value) === null;
    }

    /**
     * Hidden values are strings, booleans or JSON integers (integral values up to 2^53).
     */
    public static function isHiddenValue(mixed $value): bool
    {
        return is_string($value) || is_bool($value)
            || JsNumber::isSafeInteger($value);
    }

    public static function isUuid(mixed $value): bool
    {
        return is_string($value) && preg_match(self::UUID, $value) === 1;
    }

    private static function mismatch(): Violation
    {
        return new Violation('type_mismatch');
    }

    private static function matches(mixed $value, string $pattern, string $code, int $maxLength): ?Violation
    {
        if (! is_string($value)) {
            return self::mismatch();
        }

        $valid = Text::length($value) <= $maxLength && EcmaRegex::matches($pattern, $value);

        return $valid ? null : new Violation($code);
    }

    private static function valid(mixed $value, bool $valid, string $code): ?Violation
    {
        if (! is_string($value)) {
            return self::mismatch();
        }

        return $valid ? null : new Violation($code);
    }

    private static function decimal(Element $element, mixed $value): ?Violation
    {
        if (is_int($value) || is_float($value)) {
            return new Violation('decimal_string_required');
        }

        if (! is_string($value)) {
            return self::mismatch();
        }

        if (! Decimal::isDecimalString($value)) {
            return new Violation('invalid_number');
        }

        if ($element->type !== 'money') {
            return null;
        }

        $scale = $element->scale();
        $fraction = Decimal::fractionDigits($value);

        if ($fraction > $scale) {
            return new Violation('scale_exceeded', ['scale' => $scale]);
        }

        $normalized = Decimal::digits($value) - $fraction + $scale;

        return $normalized > Decimal::MAX_DIGITS ? new Violation('invalid_number') : null;
    }

    private static function option(Element $element, mixed $value): ?Violation
    {
        if (! is_string($value)) {
            return self::mismatch();
        }

        return in_array($value, $element->optionValues(), true) ? null : new Violation('invalid_option');
    }

    private static function options(Element $element, mixed $value): ?Violation
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return self::mismatch();
        }

        $allowed = $element->optionValues();
        $seen = [];

        foreach ($value as $item) {
            if (! is_string($item) || ! in_array($item, $allowed, true) || isset($seen[$item])) {
                return new Violation('invalid_option');
            }

            $seen[$item] = true;
        }

        return null;
    }

    private static function attachments(Element $element, mixed $value): ?Violation
    {
        if ($element->get('multiple') !== true) {
            return self::isUuid($value) ? null : new Violation('invalid_attachment');
        }

        if (! is_array($value) || ! array_is_list($value)) {
            return new Violation('invalid_attachment');
        }

        foreach ($value as $item) {
            if (! self::isUuid($item)) {
                return new Violation('invalid_attachment');
            }
        }

        return null;
    }
}
