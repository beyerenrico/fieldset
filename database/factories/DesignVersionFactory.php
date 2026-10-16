<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Documents\Designs\DesignSettings;
use App\Domain\Documents\Models\Design;
use App\Domain\Documents\Models\DesignVersion;
use FormSchema\Json\Json;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DesignVersion>
 */
class DesignVersionFactory extends Factory
{
    protected $model = DesignVersion::class;

    public function definition(): array
    {
        $settings = DesignSettings::defaults()->toArray();

        return [
            'design_id' => Design::factory(),
            'version' => 1,
            'settings' => $settings,
            'checksum' => Json::checksum($settings),
            'changelog' => 'Initial version',
            'published_at' => now(),
        ];
    }
}
