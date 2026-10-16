<?php

declare(strict_types=1);

namespace App\Domain\Dashboards;

/** Who shares a cached widget result (always within one organization). */
enum CacheScope: string
{
    /** Same for every viewer of the organization (KPIs, forms, workflow failures). */
    case Organization = 'org';

    /** Shared by viewers who see all submissions; per user otherwise (§8.6 "View submissions"). */
    case Visibility = 'visibility';

    /** Always per user (their review queue). */
    case User = 'user';
}
