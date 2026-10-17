<?php

declare(strict_types=1);

namespace App\Domain\Audit\History;

use App\Domain\Audit\Models\Activity;
use App\Domain\Forms\Models\Form;
use App\Domain\Forms\Models\FormVersion;
use App\Domain\Identity\Models\Device;
use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\IdentityProviderMapping;
use App\Domain\Identity\Models\Membership;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\SsoDomain;
use App\Domain\Identity\Models\User;
use App\Domain\Mail\Models\MailMessage;
use App\Domain\Mail\Models\MailSetting;
use App\Domain\Submissions\Models\Submission;
use App\Domain\Workflows\Models\Workflow;
use App\Domain\Workflows\Models\WorkflowRun;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Which audit entries make up the "History" of a key record (§8.11 "Records get a History tab"): the record's
 * own entries plus those of its parts — a form's versions, a submission's mails, a workflow's runs, an
 * organization's mail settings. `members` covers memberships, accounts and devices (optionally one member);
 * `sso` the identity provider, its domains and claim mappings. Applied to an organization-scoped query.
 */
final readonly class HistoryScope
{
    public const string RECORD = 'record';

    public const string MEMBERS = 'members';

    public const string SSO = 'sso';

    public function __construct(public string $kind, public ?string $subjectType = null, public ?string $subjectId = null) {}

    public static function forRecord(Model $record): self
    {
        return new self(self::RECORD, $record->getMorphClass(), (string) $record->getKey());
    }

    /**
     * @param  Builder<Activity>  $query
     * @return Builder<Activity>
     */
    public function apply(Builder $query, ?int $memberUserId = null): Builder
    {
        return match ($this->kind) {
            self::MEMBERS => $this->members($query, $memberUserId),
            self::SSO => $query->whereIn('subject_type', $this->types([IdentityProvider::class, SsoDomain::class, IdentityProviderMapping::class])),
            default => $query->where(fn (Builder $q) => $this->record($q)),
        };
    }

    /** @param  Builder<Activity>  $query */
    private function record(Builder $query): void
    {
        $query->where(fn (Builder $own) => $own->where('subject_type', $this->subjectType)->where('subject_id', $this->subjectId));
        $part = match ($this->subjectType) {
            (new Form)->getMorphClass() => [FormVersion::class, 'form_versions', 'form_id'],
            (new Submission)->getMorphClass() => [MailMessage::class, 'mail_messages', 'submission_uuid'],
            (new Workflow)->getMorphClass() => [WorkflowRun::class, 'workflow_runs', 'workflow_id'],
            (new Organization)->getMorphClass() => [MailSetting::class, 'mail_settings', 'organization_id'],
            default => null,
        };
        if ($part !== null) {
            [$class, $table, $column] = $part;
            $query->orWhere(fn (Builder $q) => $q->where('subject_type', (new $class)->getMorphClass())
                ->whereIn('subject_id', fn (QueryBuilder $ids) => $ids->selectRaw('id::text')->from($table)->where($column, $this->subjectId)));
        }
    }

    /**
     * @param  Builder<Activity>  $query
     * @return Builder<Activity>
     */
    private function members(Builder $query, ?int $userId): Builder
    {
        if ($userId === null) {
            return $query->whereIn('subject_type', $this->types([Membership::class, User::class, Device::class]));
        }

        return $query->where(fn (Builder $q) => $q
            ->where(fn (Builder $u) => $u->where('subject_type', (new User)->getMorphClass())->where('subject_id', (string) $userId))
            ->orWhere(fn (Builder $m) => $m->where('subject_type', (new Membership)->getMorphClass())
                ->whereIn('subject_id', fn (QueryBuilder $ids) => $ids->selectRaw('id::text')->from('organization_user')->where('user_id', $userId)))
            ->orWhere(fn (Builder $d) => $d->where('subject_type', (new Device)->getMorphClass())
                ->whereIn('subject_id', fn (QueryBuilder $ids) => $ids->selectRaw('id::text')->from('devices')->where('user_id', $userId))));
    }

    /**
     * @param  list<class-string<Model>>  $classes
     * @return list<string>
     */
    private function types(array $classes): array
    {
        return array_map(static fn (string $class): string => (new $class)->getMorphClass(), $classes);
    }
}
