<?php

declare(strict_types=1);

namespace App\Domain\Identity\Sso\Login;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\ExternalIdentity;
use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Sso\Data\ExternalProfile;

/** Links a user to their account at the organization's IdP (ADR 0036) and audits `sso.identity_linked`. */
final readonly class LinkExternalIdentity
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(IdentityProvider $provider, User $user, ExternalProfile $profile): ExternalIdentity
    {
        $identity = new ExternalIdentity;
        $identity->forceFill([
            'organization_id' => $provider->organization_id,
            'identity_provider_id' => $provider->id,
            'user_id' => $user->id,
            'subject' => $profile->subject,
            'issuer' => mb_substr($profile->issuer, 0, 2048),
            'last_email' => $profile->email,
            'last_login_at' => now(),
        ])->save();

        $this->audit->record('sso.identity_linked', $identity, [
            'provider' => $provider->uuid,
            'user_id' => $user->id,
            'email' => $profile->email,
            'user_created' => $user->wasRecentlyCreated,
        ], $user);

        return $identity;
    }
}
