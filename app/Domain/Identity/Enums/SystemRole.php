<?php

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

/**
 * Seeded, organization-scoped roles (BUILD_PROMPT §8.6). Roles are additive.
 */
enum SystemRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Designer = 'designer';
    case Reviewer = 'reviewer';
    case FieldWorker = 'field_worker';
    case Viewer = 'viewer';

    public function label(): string
    {
        return __('roles.'.$this->value);
    }

    /** @return list<Permission> */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => Permission::cases(),
            self::Admin => array_values(array_filter(
                Permission::cases(),
                static fn (Permission $p): bool => $p !== Permission::OrganizationDelete,
            )),
            self::Designer => [
                Permission::AdminAccess, Permission::FormsManage, Permission::WorkflowsManage,
                Permission::FormsFill, Permission::SubmissionsViewOwnForms, Permission::SubmissionsViewOwn,
                Permission::ReportsView,
            ],
            self::Reviewer => [
                Permission::AdminAccess, Permission::SubmissionsReview, Permission::FormsFill,
                Permission::SubmissionsViewAssigned, Permission::SubmissionsViewOwn, Permission::ReportsView,
            ],
            self::FieldWorker => [Permission::FormsFill, Permission::SubmissionsViewOwn],
            self::Viewer => [Permission::AdminAccess, Permission::SubmissionsViewAll, Permission::ReportsView],
        };
    }
}
