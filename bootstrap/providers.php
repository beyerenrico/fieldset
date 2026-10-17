<?php

declare(strict_types=1);

use App\Domain\Audit\AuditServiceProvider;
use App\Domain\Privacy\PrivacyServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\PlatformPanelProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\SsoServiceProvider;
use App\Providers\WorkflowServiceProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    PlatformPanelProvider::class,
    FortifyServiceProvider::class,
    HorizonServiceProvider::class,
    SsoServiceProvider::class,
    WorkflowServiceProvider::class,
    AuditServiceProvider::class,
    PrivacyServiceProvider::class,
];
