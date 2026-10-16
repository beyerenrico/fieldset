<?php

declare(strict_types=1);

namespace App\Domain\Identity\Invitations;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Actions\InviteMember;
use App\Domain\Identity\Models\Membership;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

/**
 * Sends the valid rows of a checked member CSV through InviteMember (queued mails, one `member.invited`
 * audit entry each) and records one `member.bulk_invited` summary entry (M3-T11, §8.11). A row that
 * fails at send time (e.g. accepted elsewhere meanwhile) is reported and does not stop the others.
 */
final readonly class SendBulkInvitations
{
    public function __construct(private InviteMember $invite, private AuditLogger $audit, private TenantContext $tenant) {}

    /**
     * @return array{invited: int, failed: array<int, string>} failures by CSV line
     *
     * @throws CsvInvitationException when the rows would exceed the organization's daily invitation cap
     */
    public function handle(User $actor, Organization $organization, BulkInvitationReport $report): array
    {
        $this->assertWithinDailyCap($organization, count($report->valid()));

        return $this->tenant->run($organization, function () use ($actor, $organization, $report): array {
            $invited = 0;
            $failed = [];
            foreach ($report->valid() as $row) {
                try {
                    $this->invite->handle($actor, $organization, $row->email, $row->name, $row->roles, $row->groupIds);
                    $invited++;
                } catch (ValidationException $e) {
                    $failed[$row->line] = (string) $e->validator->errors()->first();
                }
            }

            $this->audit->record('member.bulk_invited', $organization, [
                'rows' => count($report->rows),
                'invited' => $invited,
                'rejected' => count($report->invalid()),
                'failed' => count($failed),
            ], $actor);

            return ['invited' => $invited, 'failed' => $failed];
        });
    }

    /**
     * At most app.invitations_per_day invitations (single and bulk, counted by memberships invited in the
     * last 24 hours) per organization (M3 review #9): the whole file is refused, nothing is sent partially.
     */
    private function assertWithinDailyCap(Organization $organization, int $rows): void
    {
        $max = max(1, (int) config('app.invitations_per_day', 1000));
        $recent = Membership::query()->where('organization_id', $organization->id)
            ->where('invited_at', '>=', now()->subDay())->count();
        if ($recent + $rows > $max) {
            throw new CsvInvitationException(__('identity.csv_import.errors.daily_limit', ['max' => $max, 'remaining' => max(0, $max - $recent)]));
        }
    }
}
