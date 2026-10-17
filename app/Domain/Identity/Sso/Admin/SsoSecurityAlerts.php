<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Admin;

use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Notifications\NotificationEvent;
use App\Domain\Notifications\NotificationMessage;
use App\Domain\Notifications\Notifier;
use Throwable;

/**
 * Tells every other Owner of the organization (organization.delete, active) about security-relevant SSO
 * changes, in-app and by email through the Notifier on the `sso_misconfigured` event (their preferences
 * apply): the connection was set up or changed, or enforcement was switched off. Unlike misconfiguration
 * reports these are never throttled. Called after the change committed; never throws (the change stands).
 */
final readonly class SsoSecurityAlerts
{
    public const string CONNECTION_CREATED = 'connection_created';

    public const string CONNECTION_CHANGED = 'connection_changed';

    public const string ENFORCEMENT_DISABLED = 'enforcement_disabled';

    public function __construct(private Notifier $notifier) {}

    /** @return int the number of Owners notified */
    public function send(string $alert, IdentityProvider $provider, User $actor): int
    {
        try {
            $owners = PermissionHolders::query($provider->organization_id, Permission::OrganizationDelete)
                ->whereKeyNot($actor->id)->orderBy('users.id')->get();
            if ($owners->isEmpty()) {
                return 0;
            }

            $this->notifier->send(new NotificationMessage(
                NotificationEvent::SsoMisconfigured,
                'sso_admin.alerts.'.$alert.'.title',
                'sso_admin.alerts.'.$alert.'.body',
                ['provider' => $provider->name, 'actor' => $actor->name, 'email' => $actor->email],
                $this->settingsUrl($provider->organization_id),
                $provider->organization_id,
            ), $owners);

            return $owners->count();
        } catch (Throwable $e) {
            report($e);

            return 0;
        }
    }

    private function settingsUrl(int $orgId): ?string
    {
        $slug = Organization::query()->whereKey($orgId)->value('slug');

        return is_string($slug) ? route('filament.admin.pages.sso', ['tenant' => $slug]) : null;
    }
}
