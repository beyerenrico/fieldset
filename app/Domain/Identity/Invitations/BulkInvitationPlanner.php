<?php

declare(strict_types=1);

namespace App\Domain\Identity\Invitations;

use App\Domain\Identity\Enums\MembershipStatus;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Group;
use App\Domain\Identity\Models\Membership;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\LocaleResolver;
use App\Domain\Identity\Support\RoleAssignmentGuard;
use App\Domain\Identity\Tenancy\TenantContext;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Validates every row of a member CSV before anything is sent (M3-T11): email format, known roles
 * (name or DE/EN label) and groups, the role-escalation guard (RoleAssignmentGuard), duplicates within
 * the file and people who are already members. Re-inviting a pending invitation is allowed.
 */
final readonly class BulkInvitationPlanner
{
    public const int MAX_NAME = 120;

    public function __construct(
        private CsvInvitationParser $parser,
        private RoleAssignmentGuard $guard,
        private TenantContext $tenant,
    ) {}

    /** @throws CsvInvitationException */
    public function fromCsv(User $actor, Organization $organization, string $contents): BulkInvitationReport
    {
        return $this->plan($actor, $organization, $this->parser->parse($contents));
    }

    /** @param  list<CsvInvitationRow>  $rows */
    public function plan(User $actor, Organization $organization, array $rows): BulkInvitationReport
    {
        return $this->tenant->run($organization, function () use ($actor, $organization, $rows): BulkInvitationReport {
            $context = [
                'roles' => $this->roleTokens($organization),
                'assignable' => $this->guard->assignableRoles($actor, $organization),
                'groups' => Group::query()->pluck('id', 'name')->mapWithKeys(fn (mixed $id, mixed $name): array => [mb_strtolower((string) $name) => (int) $id])->all(),
                'members' => $this->memberStatuses($organization, $rows),
            ];
            $firstLine = [];
            $planned = [];
            foreach ($rows as $row) {
                $email = Str::lower(trim($row->email));
                $planned[] = $this->planRow($row, $email, $context, $firstLine[$email] ?? null);
                $firstLine[$email] ??= $row->line;
            }

            return new BulkInvitationReport($planned);
        });
    }

    /**
     * @param  array{roles: array<string, string>, assignable: list<string>, groups: array<string, int>, members: array<string, MembershipStatus>}  $context
     */
    private function planRow(CsvInvitationRow $row, string $email, array $context, ?int $duplicateOf): PlannedInvitation
    {
        $errors = [];
        $notices = [];
        $name = $row->name !== '' ? $row->name : Str::before($email, '@');

        if (Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:255']])->fails()) {
            $errors[] = __('identity.csv_import.errors.invalid_email');
        } elseif ($duplicateOf !== null) {
            $errors[] = __('identity.csv_import.errors.duplicate', ['line' => $duplicateOf]);
        } elseif (($context['members'][$email] ?? null) === MembershipStatus::Invited) {
            $notices[] = __('identity.csv_import.notices.already_invited');
        } elseif (isset($context['members'][$email])) {
            $errors[] = __('identity.csv_import.errors.already_member');
        }
        if (mb_strlen($name) > self::MAX_NAME) {
            $errors[] = __('identity.csv_import.errors.name_too_long', ['max' => self::MAX_NAME]);
        }

        [$roles, $roleErrors] = $this->roles($row->roles, $context['roles'], $context['assignable']);
        [$groupIds, $groupErrors] = $this->groups($row->groups, $context['groups']);

        return new PlannedInvitation($row->line, $email, $name, $roles, $groupIds, [...$errors, ...$roleErrors, ...$groupErrors], $notices);
    }

    /**
     * @param  list<string>  $tokens
     * @param  array<string, string>  $known  lowercase token → role name
     * @param  list<string>  $assignable
     * @return array{0: list<string>, 1: list<string>}
     */
    private function roles(array $tokens, array $known, array $assignable): array
    {
        if ($tokens === []) {
            return [[], [__('identity.csv_import.errors.roles_required')]];
        }

        $roles = [];
        $errors = [];
        foreach ($tokens as $token) {
            $role = $known[mb_strtolower($token)] ?? null;
            if ($role === null) {
                $errors[] = __('identity.csv_import.errors.unknown_role', ['role' => $token]);
            } elseif (! in_array($role, $assignable, true)) {
                $errors[] = __('identity.csv_import.errors.role_not_assignable', ['role' => $token]);
            } else {
                $roles[] = $role;
            }
        }

        return [array_values(array_unique($roles)), $errors];
    }

    /**
     * @param  list<string>  $tokens
     * @param  array<string, int>  $known  lowercase name → id
     * @return array{0: list<int>, 1: list<string>}
     */
    private function groups(array $tokens, array $known): array
    {
        $ids = [];
        $errors = [];
        foreach ($tokens as $token) {
            $id = $known[mb_strtolower($token)] ?? null;
            if ($id === null) {
                $errors[] = __('identity.csv_import.errors.unknown_group', ['group' => $token]);
            } else {
                $ids[] = $id;
            }
        }

        return [array_values(array_unique($ids)), $errors];
    }

    /** @return array<string, string> role names and DE/EN labels of system roles, lowercased → role name */
    private function roleTokens(Organization $organization): array
    {
        $tokens = [];
        foreach (Role::query()->where('organization_id', $organization->id)->pluck('name') as $name) {
            $name = (string) $name;
            $tokens[mb_strtolower($name)] = $name;
            if (SystemRole::tryFrom($name) !== null) {
                foreach (LocaleResolver::SUPPORTED as $locale) {
                    $tokens[mb_strtolower((string) __('roles.'.$name, [], $locale))] ??= $name;
                }
            }
        }

        return $tokens;
    }

    /**
     * @param  list<CsvInvitationRow>  $rows
     * @return array<string, MembershipStatus> lowercase email → membership status in the organization
     */
    private function memberStatuses(Organization $organization, array $rows): array
    {
        $emails = array_values(array_unique(array_map(fn (CsvInvitationRow $r): string => Str::lower(trim($r->email)), $rows)));
        $users = User::query()->whereIn('email', $emails)->pluck('email', 'id');

        return Membership::query()->where('organization_id', $organization->id)->whereIn('user_id', $users->keys())->get()
            ->mapWithKeys(fn (Membership $m): array => [(string) $users[$m->user_id] => $m->status])
            ->all();
    }
}
