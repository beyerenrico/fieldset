<?php

declare(strict_types=1);

namespace App\Domain\Platform\Instance\Restore;

use App\Domain\Identity\Enums\MembershipStatus;
use App\Domain\Identity\Models\User;
use Illuminate\Database\ConnectionInterface;
use stdClass;

/**
 * Re-applies an erasure (GDPR Art. 17, ADR 0039) that happened after the backup to the restored database (plan U17,
 * R64, ADR 0085): the same personal data AccountScrubber removes, written with the query builder on the target
 * connection. The account row itself gets the source's scrubbed columns (AccountCarry). Files are not touched here: the
 * one-off still mounts the pre-restore storage volume; the data-export directories of the account are returned so fieldset
 * can remove them from the restored volume.
 */
final readonly class ErasureCarry
{
    public function __construct(private ConnectionInterface $target) {}

    /** @return list<array{disk: string, path: string}> the account's data-export directories in the restored storage */
    public function scrub(int $userId, string $oldEmail): array
    {
        $email = mb_strtolower($oldEmail);
        $morph = new User()->getMorphClass();
        $db = $this->target;
        $db->table('sessions')->where('user_id', $userId)->delete();
        $db->table('personal_access_tokens')->where('tokenable_type', $morph)->where('tokenable_id', $userId)->delete();
        $db->table('devices')->where('user_id', $userId)->update([
            'name' => null, 'platform' => null,
            'revoked_at' => $db->raw('COALESCE(revoked_at, now())'), 'wipe_requested_at' => $db->raw('COALESCE(wipe_requested_at, now())'),
        ]);
        $db->table('external_identities')->where('user_id', $userId)->delete();
        $db->table('organization_user')->where('user_id', $userId)->update(['status' => MembershipStatus::Deactivated->value, 'default_group_id' => null]);
        $db->table('model_has_roles')->where('model_type', $morph)->where('model_id', $userId)->delete();
        $db->table('model_has_permissions')->where('model_type', $morph)->where('model_id', $userId)->delete();
        $db->table('group_user')->where('user_id', $userId)->delete();
        $db->table('notifications')->where('notifiable_type', $morph)->where('notifiable_id', $userId)->delete();
        $db->table('notification_preferences')->where('user_id', $userId)->delete();
        $db->table('password_reset_tokens')->whereRaw('lower(email) = ?', [$email])->delete();
        $db->table('login_links')->where('user_id', $userId)->delete();
        $db->table('mail_messages')->whereRaw('lower(recipient_email) = ?', [$email])->delete();

        return $this->deleteExports($userId);
    }

    /** @return list<array{disk: string, path: string}> */
    private function deleteExports(int $userId): array
    {
        $exports = $this->target->table('data_exports')
            ->where(fn ($query) => $query->where('requested_by', $userId)->orWhere('subject_user_id', $userId))
            ->get(['id', 'organization_id', 'disk']);
        $this->target->table('data_exports')->whereIn('id', $exports->pluck('id'))->delete();

        // DataExport::directory(): one directory per export.
        return array_values($exports->map(static fn (stdClass $export): array => ['disk' => (string) $export->disk, 'path' => 'data-exports/'.$export->organization_id.'/'.$export->id])->all());
    }
}
