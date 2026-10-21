<?php

declare(strict_types=1);

namespace App\Filament\Admin;

use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Mail\Models\MailSetting;
use App\Domain\Platform\PlatformAdmins;
use App\Filament\Admin\Pages\MailSettings;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Support\HtmlString;

/**
 * The capture-mode banner (BUILD_PROMPT_V2 R24 as narrowed by plan R59, KTD14, ADR 0054, ADR 0080): Filament render
 * hook BODY_START of the admin panel, at the top of every page of an organization while the instance mailer captures
 * mail (config `mail.capture`, set by fieldset). It cannot be dismissed.
 *
 * - Organizations on the instance mailer get the full banner: all their mail is captured.
 * - Organizations with their own SMTP server (MailSetting::usesOwnSmtp) get the narrower one: invitations, password
 *   resets and the panel's e-mail address confirmations (Filament's verification and e-mail-change notifications go
 *   through the default mailer) always use the instance mailer, so only those are captured.
 * - Platform operators see the `fieldset config set mail` command; everyone else "ask your instance owner". Members who may
 *   manage the organization also get the Mail settings link in the full variant, where an own SMTP server helps.
 */
final readonly class CaptureModeBanner
{
    public const string FULL = 'full';

    public const string NARROW = 'narrow';

    /**
     * What an operator runs on the server; the password never goes on the command line (R50). The sender is part of
     * it: a local or proxy instance would otherwise send from forms@localhost, which providers refuse.
     */
    public const string COMMAND = 'fieldset config set mail smtp --smtp-host <host> --smtp-username <user> --from-address <address> --password-stdin';

    public function __construct(private AuthFactory $auth, private PlatformAdmins $operators, private TenantContext $tenants) {}

    public function render(): HtmlString
    {
        $variant = $this->variant();
        $user = $this->auth->guard('web')->user();
        if ($variant === null || ! $user instanceof User) {
            return new HtmlString('');
        }

        return new HtmlString(view('filament.admin.capture-mode-banner', [
            'variant' => $variant,
            'operator' => $this->operators->check($user),
            'command' => self::COMMAND,
            'mailSettingsUrl' => $variant === self::FULL && MailSettings::canAccess() ? MailSettings::getUrl() : null,
        ])->render());
    }

    /** The banner for the signed-in member in the current organization; null without capture, user or organization. */
    public function variant(): ?string
    {
        $organization = Filament::getTenant();
        if (config('mail.capture') !== true || ! $this->auth->guard('web')->user() instanceof User || ! $organization instanceof Organization) {
            return null;
        }
        $settings = $this->tenants->run($organization, static fn (): ?MailSetting => MailSetting::current());

        return $settings instanceof MailSetting && $settings->usesOwnSmtp() ? self::NARROW : self::FULL;
    }
}
