<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Tenancy\BelongsToOrganization;
use Database\Factories\GroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A department or crew inside an organization (BUILD_PROMPT §2.4, §8.6).
 *
 * @property int $id
 * @property int $organization_id
 * @property string $name
 * @property string|null $description
 */
#[UseFactory(GroupFactory::class)]
#[Fillable(['name', 'description'])]
class Group extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<GroupFactory> */
    use HasFactory;

    use LogsActivity;

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('organization_id')
            ->withTimestamps();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['name', 'description'])->logOnlyDirty()->dontLogEmptyChanges()->useLogName('audit');
    }
}
