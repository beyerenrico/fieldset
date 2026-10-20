<?php

declare(strict_types=1);

namespace App\Domain\Platform\Instance\Restore;

use App\Domain\Forms\Models\Form;
use App\Domain\Identity\Models\Membership;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Workflows\Models\WebhookEndpoint;
use Illuminate\Database\ConnectionInterface;
use stdClass;

/**
 * The organization half of the R64 carry (plan U17, KTD18, ADR 0085 amendment after review), from the pre-restore
 * database (`source`) onto the restored one (`target`): what an administrator took away after the backup stays away.
 *
 * - SSO (SsoCarry): external identities, identity providers, their claim mappings and SSO domains;
 * - group memberships (`group_user`) of accounts both databases know, and form access grants (`form_access`), removed
 *   in the source → removed;
 * - organizations soft-deleted in the source → soft-deleted;
 * - webhook endpoints deleted in the source → deleted, deactivated → deactivated.
 *
 * The cascade guard keeps a grant or membership whose form, group or organization the source no longer has; in mode
 * audit (CarryScope) a removal needs an audit entry after the backup (`member.groups_changed`, `form.access_changed`,
 * the endpoint's `deleted`/`updated`), a soft delete its timestamp after it. Query-builder writes only (no model events,
 * no audit entries); nothing created after the backup is added.
 */
final readonly class ScopeCarry
{
    public function __construct(private ConnectionInterface $source, private ConnectionInterface $target, private CarryScope $scope) {}

    /** @return array<string, int> */
    public function carry(): array
    {
        return [
            ...new SsoCarry($this->source, $this->target, $this->scope)->carry(),
            'group_memberships_removed' => $this->groupMemberships(),
            'form_access_removed' => $this->formAccess(),
            'organizations_deleted' => $this->organizations(),
            'webhook_endpoints_deleted' => $this->webhookEndpoints(),
            'webhook_endpoints_deactivated' => $this->webhooks(),
        ];
    }

    /** Group memberships the source dropped, for accounts both databases know (a missing account is deactivated). */
    private function groupMemberships(): int
    {
        $users = array_flip(array_map(strval(...), $this->source->table('users')->pluck('id')->all()));
        $kept = array_flip($this->source->table('group_user')->get(['group_id', 'user_id'])->map(static fn (stdClass $row): string => $row->group_id.'|'.$row->user_id)->all());
        $audited = $this->groupChanges();
        $count = 0;
        foreach ($this->target->table('group_user')->get(['id', 'organization_id', 'group_id', 'user_id']) as $row) {
            if (! isset($users[(string) $row->user_id]) || isset($kept[$row->group_id.'|'.$row->user_id])
                || $this->scope->orphaned('group_memberships_removed', ['groups' => $row->group_id, 'organizations' => $row->organization_id])
                || ! $this->scope->permits('group_memberships_removed', $audited, $row->organization_id.'|'.$row->user_id)) {
                continue;
            }
            $count += $this->target->table('group_user')->where('id', $row->id)->delete();
        }

        return $count;
    }

    /** Form access grants (form, group or role) the source no longer has. */
    private function formAccess(): int
    {
        $key = static fn (stdClass $row): string => $row->form_id.'|'.($row->group_id ?? '').'|'.($row->role_name ?? '');
        $kept = array_flip($this->source->table('form_access')->get(['form_id', 'group_id', 'role_name'])->map($key)->all());
        $audited = $this->scope->subjects(Form::class, ['form.access_changed']);
        $count = 0;
        foreach ($this->target->table('form_access')->get(['id', 'organization_id', 'form_id', 'group_id', 'role_name']) as $row) {
            if (isset($kept[$key($row)])
                || $this->scope->orphaned('form_access_removed', ['forms' => $row->form_id, 'groups' => $row->group_id, 'organizations' => $row->organization_id])
                || ! $this->scope->permits('form_access_removed', $audited, $row->form_id)) {
                continue;
            }
            $count += $this->target->table('form_access')->where('id', $row->id)->delete();
        }

        return $count;
    }

    private function organizations(): int
    {
        $audited = $this->scope->subjects(Organization::class, ['deleted']);
        $count = 0;
        foreach ($this->source->table('organizations')->whereNotNull('deleted_at')->get(['id', 'deleted_at']) as $organization) {
            $row = $this->target->table('organizations')->where('id', $organization->id)->whereNull('deleted_at');
            if ($row->exists() && $this->scope->permits('organizations_deleted', $audited, $organization->id, $organization->deleted_at)) {
                $count += $row->update(['deleted_at' => $organization->deleted_at]);
            }
        }

        return $count;
    }

    private function webhookEndpoints(): int
    {
        $kept = array_flip(array_map(strval(...), $this->source->table('webhook_endpoints')->pluck('id')->all()));
        $audited = $this->scope->subjects(WebhookEndpoint::class, ['deleted']);
        $count = 0;
        foreach ($this->target->table('webhook_endpoints')->get(['id', 'organization_id']) as $endpoint) {
            if (isset($kept[(string) $endpoint->id]) || $this->scope->orphaned('webhook_endpoints_deleted', ['organizations' => $endpoint->organization_id])
                || ! $this->scope->permits('webhook_endpoints_deleted', $audited, $endpoint->id)) {
                continue;
            }
            $count += $this->target->table('webhook_endpoints')->where('id', $endpoint->id)->delete();
        }

        return $count;
    }

    private function webhooks(): int
    {
        $inactive = $this->source->table('webhook_endpoints')->where('active', false)->pluck('id')->all();
        $audited = $this->scope->subjects(WebhookEndpoint::class, ['updated'], 'active');
        $count = 0;
        foreach ($inactive === [] ? [] : $this->target->table('webhook_endpoints')->whereIn('id', $inactive)->where('active', true)->pluck('id')->all() as $id) {
            if ($this->scope->permits('webhook_endpoints_deactivated', $audited, $id)) {
                $count += $this->target->table('webhook_endpoints')->where('id', $id)->update(['active' => false]);
            }
        }

        return $count;
    }

    /**
     * `<organization>|<user>` pairs whose groups an audit entry after the backup changed: the membership is the subject,
     * or the account (then every organization of it). Null in mode state.
     *
     * @return array<string, true>|null
     */
    private function groupChanges(): ?array
    {
        $memberships = $this->scope->subjects(Membership::class, ['member.groups_changed']);
        $users = $this->scope->subjects(User::class, ['member.groups_changed']);
        if ($memberships === null || $users === null) {
            return null;
        }
        $rows = $this->source->table('organization_user')
            ->where(fn ($query) => $query->whereIn('id', array_keys($memberships))->orWhereIn('user_id', array_keys($users)))
            ->get(['organization_id', 'user_id']);

        return array_fill_keys($rows->map(static fn (stdClass $row): string => $row->organization_id.'|'.$row->user_id)->all(), true);
    }
}
