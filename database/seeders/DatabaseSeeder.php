<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Identity\Actions\SyncPermissionCatalog;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(SyncPermissionCatalog $catalog): void
    {
        $catalog->handle();

        if (! app()->isProduction()) {
            $this->call(DemoSeeder::class);
            $this->call(SsoDemoSeeder::class);
        }
    }
}
