<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Exports;

use App\Domain\Audit\Models\Activity;
use App\Domain\Audit\Query\AuditLogQuery;
use App\Domain\Identity\Models\Device;
use App\Domain\Identity\Models\Membership;
use App\Domain\Identity\Models\User;
use App\Domain\Submissions\Models\Attachment;
use App\Domain\Submissions\Models\Submission;
use App\Domain\Submissions\Support\SubmissionVisibility;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;
use ZipArchive;

/**
 * A user's personal data held by one organization, as a ZIP of JSON files (GDPR Art. 15/20, ADR 0039):
 * profile, memberships (self-service: all of the account's memberships; requested by an admin: this
 * organization's), devices, the submissions the user created or submitted in this organization (data where
 * the requester may still see the submission, attachment metadata, no files) and the audit entries about the
 * user or caused by them in this organization (details only for the user's own actions).
 * Secrets (password hash, MFA secret, recovery codes, tokens) are never included.
 */
final readonly class UserDataArchive
{
    public function __construct(private SubmissionVisibility $visibility) {}

    /**
     * @param  User  $viewer  the requester: submission data is included only where they may still see it
     * @return array{0: string, 1: int} the ZIP path and the number of submissions and audit entries
     */
    public function write(User $user, User $viewer, int $organizationId, bool $selfService, string $timezone, string $directory): array
    {
        $files = [
            'profile.json' => $this->json($directory.'/profile.json', $this->profile($user)),
            'memberships.json' => $this->json($directory.'/memberships.json', $this->memberships($user, $selfService ? null : $organizationId)),
            'devices.json' => $this->json($directory.'/devices.json', $this->devices($user)),
        ];
        [$files['submissions.json'], $submissions] = $this->submissions($user, $viewer, $directory.'/submissions.json');
        [$files['audit-log.json'], $entries] = $this->audit($user, $organizationId, $timezone, $directory.'/audit-log.json');
        file_put_contents($directory.'/README.txt', (string) __('privacy.exports.readme', ['date' => now()->setTimezone($timezone)->toIso8601String()]));
        $files['README.txt'] = $directory.'/README.txt';

        $zipPath = $directory.'/personal-data.zip';
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The export archive could not be created.');
        }
        foreach ($files as $name => $path) {
            $zip->addFile($path, $name);
        }
        $zip->close();

        return [$zipPath, $submissions + $entries];
    }

    /** @return array<string, mixed> */
    private function profile(User $user): array
    {
        $attributes = $user->getAttributes();

        return [
            'id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'locale' => $user->locale, 'timezone' => $user->timezone,
            'status' => $user->status->value, 'email_verified_at' => $attributes['email_verified_at'] ?? null,
            'mfa_enabled' => $user->two_factor_confirmed_at !== null, 'deactivated_at' => $user->deactivated_at?->toIso8601String(),
            'created_at' => $attributes['created_at'] ?? null,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function memberships(User $user, ?int $organizationId): array
    {
        $memberships = [];
        $query = Membership::query()->with('organization:id,name')->where('user_id', $user->id)
            ->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId))->orderBy('id');
        foreach ($query->get() as $membership) {
            $memberships[] = [
                'organization' => $membership->organization->name ?? null, 'status' => $membership->status->value,
                'invited_at' => $this->iso($membership->getAttribute('invited_at')), 'joined_at' => $this->iso($membership->getAttribute('joined_at')),
            ];
        }

        return $memberships;
    }

    /** @return list<array<string, mixed>> */
    private function devices(User $user): array
    {
        return array_values(Device::query()->where('user_id', $user->id)->orderBy('id')->get()
            ->map(fn (Device $d): array => [
                'name' => $d->name, 'platform' => $d->platform, 'last_seen_at' => $d->last_seen_at?->toIso8601String(),
                'revoked_at' => $d->revoked_at?->toIso8601String(), 'created_at' => $this->iso($d->getAttribute('created_at')),
            ])->all());
    }

    /** @return array{0: string, 1: int} */
    private function submissions(User $user, User $viewer, string $path): array
    {
        $file = new JsonArrayFile($path);
        $query = Submission::query()->with(['form:id,key,name', 'formVersion:id,version', 'attachments'])
            ->where(fn (Builder $q) => $q->where('created_by', $user->id)->orWhere('submitted_by', $user->id));
        foreach ($query->lazyById(200, 'uuid') as $submission) {
            /** @var Submission $submission */
            $visible = $this->visibility->canView($viewer, $submission);
            $file->add([
                'uuid' => $submission->uuid, 'form' => ['key' => $submission->form->key, 'name' => $submission->form->name],
                'version' => $submission->formVersion->version, 'number' => $submission->number, 'status' => $submission->status->value,
                // The content is the organization's record (customer data); it follows today's visibility rules.
                'data' => $visible ? $submission->data : null, 'data_withheld' => ! $visible,
                'device' => $submission->device, 'geo' => $submission->geo,
                'submitted_at' => $submission->submitted_at?->toIso8601String(), 'created_at' => $submission->created_at->toIso8601String(),
                'attachments' => $submission->attachments->map(fn (Attachment $a): array => [
                    'uuid' => $a->uuid, 'kind' => $a->kind, 'element' => $a->element_key, 'name' => $a->original_name,
                    'mime' => $a->mime, 'size' => $a->size, 'sha256' => $a->sha256,
                ])->values()->all(),
            ]);
        }

        return [$path, $file->close()];
    }

    /** @return array{0: string, 1: int} */
    private function audit(User $user, int $organizationId, string $timezone, string $path): array
    {
        $file = new JsonArrayFile($path);
        $memberships = Membership::query()->where('user_id', $user->id)->pluck('id')->map(fn (mixed $id): string => (string) $id)->all();
        $userType = (new User)->getMorphClass();
        $query = AuditLogQuery::forOrganization($organizationId)->where(fn (Builder $q) => $q
            ->where(fn (Builder $c) => $c->where('causer_type', $userType)->where('causer_id', $user->id))
            ->orWhere(fn (Builder $s) => $s->where('subject_type', $userType)->where('subject_id', (string) $user->id))
            ->orWhere(fn (Builder $s) => $s->where('subject_type', (new Membership)->getMorphClass())->whereIn('subject_id', $memberships === [] ? ['0'] : $memberships)));
        foreach ($query->lazyById(500) as $entry) {
            /** @var Activity $entry */
            $byUser = $entry->causer_type === $userType && (int) $entry->causer_id === $user->id;
            $item = [
                'time' => $entry->created_at?->setTimezone($timezone)->toIso8601String(), 'event' => $entry->event, 'by_you' => $byUser,
                'subject' => $entry->subject_type === null ? null : class_basename($entry->subject_type).' #'.$entry->subject_id,
            ];
            // Details of entries caused by others (their IP address, user agent, the full diff) are not the user's data.
            $file->add($byUser ? [...$item, 'ip_address' => $entry->ip_address, 'user_agent' => $entry->user_agent,
                'changes' => $entry->attribute_changes?->all() ?? [], 'properties' => $entry->properties?->all() ?? []] : $item);
        }

        return [$path, $file->close()];
    }

    private function iso(mixed $value): ?string
    {
        return $value instanceof \DateTimeInterface ? CarbonImmutable::instance($value)->toIso8601String() : (is_string($value) ? $value : null);
    }

    /** @param  array<array-key, mixed>  $data */
    private function json(string $path, array $data): string
    {
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $path;
    }
}
