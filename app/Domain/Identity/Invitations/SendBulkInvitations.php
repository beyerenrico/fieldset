<?php

declare(strict_types=1);

namespace App\Domain\Identity\Invitations;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Actions\InviteMember;
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

    /** @return array{invited: int, failed: array<int, string>} failures by CSV line */
    public function handle(User $actor, Organization $organization, BulkInvitationReport $report): array
    {
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
}
