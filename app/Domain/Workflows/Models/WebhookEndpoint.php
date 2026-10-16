<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Models;

use App\Domain\Identity\Tenancy\BelongsToOrganization;
use App\Domain\Workflows\Policies\WebhookEndpointPolicy;
use Database\Factories\WebhookEndpointFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Outbound webhook target of the `webhook` step (§6.4, §8.3). The signing secret is encrypted at
 * rest, hidden from serialization and never written to the audit log.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $name
 * @property string $url
 * @property string $secret
 * @property bool $active
 * @property int|null $created_by
 */
#[UseFactory(WebhookEndpointFactory::class)]
#[UsePolicy(WebhookEndpointPolicy::class)]
#[Fillable(['name', 'url', 'active', 'created_by'])]
#[Hidden(['secret'])]
class WebhookEndpoint extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<WebhookEndpointFactory> */
    use HasFactory;

    use LogsActivity;

    protected $attributes = ['active' => true, 'created_by' => null];

    protected function casts(): array
    {
        return ['secret' => 'encrypted', 'active' => 'boolean'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['name', 'url', 'active'])->logOnlyDirty()->dontLogEmptyChanges()->useLogName('audit');
    }
}
