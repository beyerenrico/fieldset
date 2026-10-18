<?php

declare(strict_types=1);

namespace App\Domain\Platform\Instance;

use App\Domain\Identity\LoginLinks\IssuedLoginLink;
use App\Domain\Identity\Models\User;
use Carbon\CarbonInterface;

/**
 * What `SetOperatorEmail` did: the account, whether it was created or newly granted, who lost the role, the link it
 * issued, or the expiry of a usable link it kept instead of replacing it.
 */
final readonly class OperatorEmailResult
{
    public const string LINK_ISSUED = 'issued';

    public const string LINK_OUTSTANDING = 'outstanding';

    public const string LINK_NONE = 'none';

    /** @param  list<string>  $revoked */
    public function __construct(
        public User $user,
        public bool $created,
        public bool $granted,
        public array $revoked,
        public ?IssuedLoginLink $link,
        public ?CarbonInterface $outstandingUntil = null,
    ) {}

    /** `issued`, `outstanding` (an earlier link can still be used and was kept) or `none` (the account has a password). */
    public function linkState(): string
    {
        return match (true) {
            $this->link instanceof IssuedLoginLink => self::LINK_ISSUED,
            $this->outstandingUntil instanceof CarbonInterface => self::LINK_OUTSTANDING,
            default => self::LINK_NONE,
        };
    }

    /** When the issued or the kept link expires (ISO 8601, UTC). */
    public function linkExpiresAt(): ?string
    {
        $expires = $this->link instanceof IssuedLoginLink ? $this->link->link->expires_at : $this->outstandingUntil;

        return $expires?->toImmutable()->utc()->toIso8601ZuluString();
    }

    public function changed(): bool
    {
        return $this->created || $this->granted || $this->revoked !== [] || $this->link instanceof IssuedLoginLink;
    }
}
