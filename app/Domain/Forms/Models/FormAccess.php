<?php

declare(strict_types=1);

namespace App\Domain\Forms\Models;

use App\Domain\Identity\Models\Group;
use App\Domain\Identity\Tenancy\BelongsToOrganization;
use Database\Factories\FormAccessFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Grants a group or a role the right to fill a form in the Field App (BUILD_PROMPT §8.6).
 * Exactly one of group_id / role_name is set (database check constraint).
 *
 * @property int $id
 * @property int $organization_id
 * @property int $form_id
 * @property int|null $group_id
 * @property string|null $role_name
 */
#[UseFactory(FormAccessFactory::class)]
class FormAccess extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<FormAccessFactory> */
    use HasFactory;

    protected $table = 'form_access';

    protected $fillable = ['form_id', 'group_id', 'role_name'];

    protected $attributes = ['group_id' => null, 'role_name' => null];

    /** @return BelongsTo<Form, $this> */
    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    /** @return BelongsTo<Group, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }
}
