<?php

declare(strict_types=1);

namespace FormSchema\Evaluation;

use FormSchema\Logic\DateValue;
use stdClass;

/**
 * Shape checks for signature and location values (spec §3.2). Objects must hold exactly the
 * documented properties.
 */
final class SignatureLocation
{
    private const SIGNATURE_KEYS = ['attachment', 'signedAt', 'signerName'];

    private const LOCATION_KEYS = ['accuracy', 'capturedAt', 'lat', 'lng'];

    public static function checkSignature(mixed $value): ?Violation
    {
        if (! $value instanceof stdClass) {
            return new Violation('type_mismatch');
        }

        $valid = self::hasExactly($value, self::SIGNATURE_KEYS)
            && TypeChecker::isUuid($value->attachment)
            && is_string($value->signerName)
            && DateValue::parseDateTime($value->signedAt) !== null;

        return $valid ? null : new Violation('invalid_signature');
    }

    public static function checkLocation(mixed $value): ?Violation
    {
        if (! $value instanceof stdClass) {
            return new Violation('type_mismatch');
        }

        $valid = self::hasExactly($value, self::LOCATION_KEYS)
            && self::inRange($value->lat, -90.0, 90.0)
            && self::inRange($value->lng, -180.0, 180.0)
            && self::inRange($value->accuracy, 0.0, INF)
            && DateValue::parseDateTime($value->capturedAt) !== null;

        return $valid ? null : new Violation('invalid_location');
    }

    /**
     * @param  list<string>  $keys  sorted
     */
    private static function hasExactly(stdClass $value, array $keys): bool
    {
        $present = array_map('strval', array_keys(get_object_vars($value)));
        sort($present);

        return $present === $keys;
    }

    private static function inRange(mixed $value, float $min, float $max): bool
    {
        return (is_int($value) || (is_float($value) && is_finite($value))) && $value >= $min && $value <= $max;
    }
}
