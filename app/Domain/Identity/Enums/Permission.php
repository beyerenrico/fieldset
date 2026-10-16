<?php

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

/**
 * Capability catalog. One permission per row of the BUILD_PROMPT §8.6 matrix.
 */
enum Permission: string
{
    case AdminAccess = 'admin.access';
    case OrganizationManage = 'organization.manage';
    case OrganizationDelete = 'organization.delete';
    case MembersManage = 'members.manage';
    case FormsManage = 'forms.manage';
    case WorkflowsManage = 'workflows.manage';
    case SubmissionsReview = 'submissions.review';
    case FormsFill = 'forms.fill';
    case FormsFillAll = 'forms.fill_all';
    case SubmissionsViewAll = 'submissions.view_all';
    case SubmissionsViewOwnForms = 'submissions.view_own_forms';
    case SubmissionsViewAssigned = 'submissions.view_assigned';
    case SubmissionsViewOwn = 'submissions.view_own';
    case ReportsView = 'reports.view';
    case AuditView = 'audit.view';

    public function label(): string
    {
        return __('permissions.'.$this->value);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $p): string => $p->value, self::cases());
    }
}
