<?php

declare(strict_types=1);

namespace App\Domain\Instance\Runtime;

/** Where the runtime state of a reading came from. */
enum RuntimeSource: string
{
    /** FSA_RUNTIME_PATH is not set (checkout install): the app behaves as before. */
    case NotConfigured = 'not_configured';
    /** The current runtime.json. */
    case File = 'file';
    /** runtime.json is unusable; the last good value this process read stays in force. */
    case LastGood = 'last_good';
    /** runtime.json is unusable and this process never read a good one: APP_URL with the gate raised. */
    case Fallback = 'fallback';
}
