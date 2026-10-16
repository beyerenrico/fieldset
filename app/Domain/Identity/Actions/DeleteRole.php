<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

final readonly class DeleteRole
{
    public function __construct(private PermissionRegistrar $registrar, private AuditLogger $audit) {}

    public function handle(User $actor, Role $role): void
    {
        $this->audit->record('role.deleted', $role, ['name' => $role->name], $actor);
        // Drop form grants of the deleted role so a future role with the same name inherits nothing.
        DB::table('form_access')->where('organization_id', $role->organization_id)->where('role_name', $role->name)->delete();
        $role->delete();
        $this->registrar->forgetCachedPermissions();
    }
}
