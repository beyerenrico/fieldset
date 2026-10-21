<?php

declare(strict_types=1);

namespace App\Domain\Instance\Lifecycle;

use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;

/**
 * The `data.mail` block of `fieldset install`, `fieldset status`, `fieldset demo` and `fieldset config set mail` (ADR 0080): how the
 * instance delivers mail and where the catcher UI is. In local and proxy mode that UI asks for its own Basic login, so
 * the block names the command that prints it (`catcher_login_command`); in domain mode the edge signs in for a
 * /platform session instead (ADR 0075), and without a catcher there is nothing to sign in to.
 */
final class MailSummary
{
    public const string CATCHER_LOGIN_COMMAND = 'fieldset config show --catcher-login';

    /** @return array{mode: string, catcher_url: string|null, catcher_login_command?: string} */
    public static function of(InstanceState $state): array
    {
        $settings = InstanceSettings::of($state);

        return [
            'mode' => $settings->capturesMail() ? 'capture' : 'smtp',
            'catcher_url' => $settings->catcherUrl(),
            ...(self::needsCatcherLogin($state) ? ['catcher_login_command' => self::CATCHER_LOGIN_COMMAND] : []),
        ];
    }

    /** The catcher runs and its UI is reached on a loopback port, behind its own Basic login (not the edge's). */
    public static function needsCatcherLogin(InstanceState $state): bool
    {
        return InstanceSettings::of($state)->runsCatcher() && $state->mode() !== 'domain';
    }
}
