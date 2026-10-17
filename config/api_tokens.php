<?php

declare(strict_types=1);

/*
| Integration tokens (BUILD_PROMPT §10, ADR 0043). Tokens are created on the "API tokens" page of the dashboard.
*/
return [
    // Preselected expiry of a new token in days; users may still choose 7–365 days or "never".
    'default_expiry_days' => (int) env('API_TOKEN_DEFAULT_EXPIRY_DAYS', 90),
];
