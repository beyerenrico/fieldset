<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Enums;

/** A queued data export: a user's personal data (ZIP of JSON) or the audit log (CSV). */
enum DataExportKind: string
{
    case UserData = 'user_data';
    case AuditLog = 'audit_log';
}
