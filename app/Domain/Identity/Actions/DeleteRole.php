<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use Spatie\Permission\PermissionRegistrar;

final readonly class DeleteRole
{
    public function __construct(private PermissionRegistrar $registrar, private AuditLogger $audit) {}

    public function handle(User $actor, Role $role): void
    {
        $this->audit->record('role.deleted', $role, ['name' => $role->name], $actor);
        $role->delete();
        $this->registrar->forgetCachedPermissions();
    }
}
