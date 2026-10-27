<?php

declare(strict_types=1);

namespace App\Domain\Instance\Windows;

/**
 * How much the launcher learned from Windows for this command (2.1.1 U6, KTD8): the shim asks powershell.exe for every
 * fact on install and `config set phone-https|lan-address`, and on doctor and uninstall while phones use the local CA;
 * for the LAN addresses and `.wslconfig` on doctor and uninstall otherwise; only for the LAN addresses on other commands
 * while phones use the local CA; and nothing otherwise.
 */
enum FactsScope: string
{
    /** The launcher asked Windows nothing: this command needs no Windows facts (or a launcher before 2.1.1). */
    case Skipped = 'skipped';

    /** Interop is off, powershell.exe is missing, or its answer was incomplete: every fact is unknown (R30). */
    case Unavailable = 'unavailable';

    /** The cheap address query: the LAN addresses and their network profiles only. */
    case Addresses = 'addresses';

    /**
     * The LAN addresses and `.wslconfig` (networking mode, idle timeouts): doctor and uninstall while phones do not use
     * the local CA, whose checks need nothing else from Windows.
     */
    case Light = 'light';

    case Full = 'full';
}
