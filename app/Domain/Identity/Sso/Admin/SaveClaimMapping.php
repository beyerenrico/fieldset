<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Admin;

use App\Domain\Identity\Models\Group;
use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\IdentityProviderMapping;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\RoleAssignmentGuard;
use App\Domain\Identity\Tenancy\TenantContext;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as ValidatorInstance;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Creates or updates a claim mapping (ADR 0036): a claim value (claim default `groups`) maps to a role XOR
 * a group of the provider's organization. Owner and Owner-level roles are never targets, so a login can
 * never grant or take away Owner (and with it the break-glass Owner's role); a role must also be one the
 * actor may assign themselves (RoleAssignmentGuard). Audited by the mapping model (one entry per change).
 * Error keys: claim, value, role_id, group_id, target (role XOR group).
 */
final readonly class SaveClaimMapping
{
    private const string CLAIM = '/^[A-Za-z0-9_.:\/-]{1,120}$/';

    public function __construct(
        private SsoTargets $targets,
        private RoleAssignmentGuard $guard,
        private TenantContext $tenant,
        private CauserResolver $causer,
    ) {}

    /**
     * @param  array{claim?: string|null, value?: string|null, role_id?: int|string|null, group_id?: int|string|null}  $input
     *
     * @throws ValidationException
     */
    public function handle(IdentityProvider $provider, array $input, User $actor, ?IdentityProviderMapping $mapping = null): IdentityProviderMapping
    {
        return $this->tenant->run($provider->organization_id, function () use ($provider, $input, $actor, $mapping): IdentityProviderMapping {
            $org = $provider->organization;
            $data = [
                'claim' => filled($input['claim'] ?? null) ? trim((string) $input['claim']) : ProviderSettings::DEFAULT_GROUPS_CLAIM,
                'value' => is_string($input['value'] ?? null) ? trim($input['value']) : null,
                'role_id' => filled($input['role_id'] ?? null) ? $input['role_id'] : null,
                'group_id' => filled($input['group_id'] ?? null) ? $input['group_id'] : null,
            ];

            $validator = Validator::make($data, [
                'claim' => ['required', 'string', 'regex:'.self::CLAIM],
                'value' => ['required', 'string', 'max:255', 'not_regex:/[\x00-\x1F\x7F]/'],
            ], [], ['claim' => (string) __('sso_admin.mappings.claim'), 'value' => (string) __('sso_admin.mappings.value')]);
            $validator->after(fn (ValidatorInstance $v) => $this->checkTarget($v, $org, $actor, $data));
            $validator->after(fn (ValidatorInstance $v) => $this->checkUnique($v, $provider, $data, $mapping));
            $validator->validate();

            $mapping ??= new IdentityProviderMapping(['identity_provider_id' => $provider->id]);
            $mapping->fill([
                'claim' => $data['claim'], 'value' => $data['value'],
                'role_id' => $data['role_id'] === null ? null : (int) $data['role_id'],
                'group_id' => $data['group_id'] === null ? null : (int) $data['group_id'],
            ]);
            $this->causer->withCauser($actor, static fn (): bool => $mapping->save());

            return $mapping;
        });
    }

    /** @param  array<string, mixed>  $data */
    private function checkTarget(ValidatorInstance $v, Organization $org, User $actor, array $data): void
    {
        if (($data['role_id'] === null) === ($data['group_id'] === null)) {
            $v->errors()->add('target', __('sso_admin.mappings.errors.target'));

            return;
        }
        if ($data['group_id'] !== null && ! $this->targets->group($org, $data['group_id']) instanceof Group) {
            $v->errors()->add('group_id', __('sso_admin.mappings.errors.unknown_group'));
        }
        if ($data['role_id'] === null) {
            return;
        }

        $role = $this->targets->role($org, $data['role_id']);
        if (! $role instanceof Role) {
            $v->errors()->add('role_id', __('sso_admin.mappings.errors.unknown_role'));
        } elseif ($this->targets->isOwnerLevel($role)) {
            $v->errors()->add('role_id', __('sso_admin.mappings.errors.owner_role'));
        } elseif (! in_array($role->name, $this->guard->assignableRoles($actor, $org), true)) {
            $v->errors()->add('role_id', __('sso_admin.mappings.errors.role_not_assignable'));
        }
    }

    /** @param  array<string, mixed>  $data */
    private function checkUnique(ValidatorInstance $v, IdentityProvider $provider, array $data, ?IdentityProviderMapping $mapping): void
    {
        if ($v->errors()->isNotEmpty()) {
            return;
        }
        $exists = IdentityProviderMapping::query()->where('identity_provider_id', $provider->id)
            ->where('claim', $data['claim'])->where('value', $data['value'])
            ->where('role_id', $data['role_id'])->where('group_id', $data['group_id'])
            ->when($mapping instanceof IdentityProviderMapping, fn ($q) => $q->whereKeyNot($mapping?->id))
            ->exists();
        if ($exists) {
            $v->errors()->add('value', __('sso_admin.mappings.errors.duplicate'));
        }
    }
}
