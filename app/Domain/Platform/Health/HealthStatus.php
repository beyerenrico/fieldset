<?php

declare(strict_types=1);

namespace App\Domain\Platform\Health;

enum HealthStatus: string
{
    case Ok = 'ok';
    case Warn = 'warn';
    case Fail = 'fail';

    /** Filament colour of the status badge. */
    public function color(): string
    {
        return match ($this) {
            self::Ok => 'success',
            self::Warn => 'warning',
            self::Fail => 'danger',
        };
    }
}
