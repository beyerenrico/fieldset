<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Enums;

/** Error codes of a refused SSO login; the Field App receives them as `?sso_error=<code>` (M7 contract). */
enum SsoRefusal: string
{
    case SsoFailed = 'sso_failed';
    case SsoDenied = 'sso_denied';
    case AccountInactive = 'account_inactive';
    case NotMember = 'not_member';
    case DomainNotVerified = 'domain_not_verified';
    case EmailUnverified = 'email_unverified';
    case ProviderUnavailable = 'provider_unavailable';
}
