<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Enums;

/** Outcome of one DNS TXT check of an SSO domain (ADR 0036). */
enum DomainVerification: string
{
    case Verified = 'verified';
    /** No TXT record at the verification name. */
    case Missing = 'missing';
    /** TXT records exist, none carries this organization's token. */
    case Mismatch = 'mismatch';
    /** The lookup itself failed (timeout, SERVFAIL). */
    case Error = 'error';
}
