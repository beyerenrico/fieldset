<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Health;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Sso\Admin\PermissionHolders;
use App\Domain\Identity\Sso\Contracts\SsoMisconfigurationReporter;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Notifications\NotificationEvent;
use App\Domain\Notifications\NotificationMessage;
use App\Domain\Notifications\Notifier;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Throwable;

/**
 * Raises `sso_misconfigured` (§8.9, ADR 0036) to the organization's members who manage users and SSO
 * (members.manage: Owners, Admins, custom roles with it), in-app and by email through the Notifier (their
 * preferences apply). At most once per provider and reason every `sso.notify_every` seconds (a cache lock
 * that is never released, so it expires with the window); each notification is audited as `sso.misconfigured`.
 * Reasons with their own text (notifications.messages.sso_misconfigured.reasons): domain_unverified,
 * break_glass_invalid and the provider faults of the health check and login (discovery, jwks, metadata,
 * issuer, tenant, audience, signature, saml_signature, saml_issuer, invalid_client, config, blocked_host);
 * any other reason uses the generic text with the detail. Never throws: login flows and checks call it.
 */
final readonly class NotifySsoMisconfiguration implements SsoMisconfigurationReporter
{
    public function __construct(private Notifier $notifier, private AuditLogger $audit, private TenantContext $tenant) {}

    public function report(IdentityProvider $p, string $reason, string $detail): void
    {
        $reason = Str::limit((string) preg_replace('/[^a-z0-9_]+/', '_', strtolower(trim($reason))), 64, '');
        $detail = Str::limit(trim($detail), 500);
        $lock = Cache::lock('sso:misconfigured:'.$p->id.':'.sha1($reason), max(1, (int) config('sso.notify_every', 21600)));

        try {
            if (! $lock->get()) {
                return; // already notified for this provider and reason in the current window
            }

            $this->tenant->run($p->organization_id, function () use ($p, $reason, $detail): void {
                $recipients = $this->administrators($p->organization_id);
                $this->notifier->send($this->message($p, $reason, $detail), $recipients);
                $this->audit->record('sso.misconfigured', $p, [
                    'reason' => $reason, 'detail' => $detail, 'recipients' => $recipients->count(),
                ]);
            });
        } catch (Throwable $e) {
            rescue(static fn () => $lock->forceRelease(), report: false); // the next report may try again
            report($e);
        }
    }

    private function message(IdentityProvider $p, string $reason, string $detail): NotificationMessage
    {
        $specific = 'notifications.messages.sso_misconfigured.reasons.'.$reason;

        return new NotificationMessage(
            NotificationEvent::SsoMisconfigured,
            'notifications.messages.sso_misconfigured.title',
            Lang::has($specific, 'en') ? $specific : 'notifications.messages.sso_misconfigured.reasons.other',
            ['provider' => $p->name, 'reason' => $reason, 'detail' => $detail],
            $this->settingsUrl($p->organization_id),
            $p->organization_id,
            status: 'danger',
        );
    }

    /** @return Collection<int, User> active members holding members.manage in the organization */
    private function administrators(int $orgId): Collection
    {
        return PermissionHolders::query($orgId, Permission::MembersManage)->orderBy('users.id')->get();
    }

    private function settingsUrl(int $orgId): ?string
    {
        try {
            $slug = Organization::query()->whereKey($orgId)->value('slug');

            return is_string($slug) ? route('filament.admin.pages.sso', ['tenant' => $slug]) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
