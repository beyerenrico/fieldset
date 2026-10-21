<?php

declare(strict_types=1);

namespace App\Domain\Instance\Config;

use SensitiveParameter;

/**
 * SMTP user name and password for `fieldset config set mail smtp` (R25, R50, ADR 0080). They live only in the app roles'
 * env files (MAIL_USERNAME, MAIL_PASSWORD), never in the state, the journal, the JSON or an audit entry; the password
 * arrives through stdin or a file. The user name is treated as a credential too (`config show` redacts it).
 */
final readonly class SmtpCredentials
{
    public function __construct(
        #[SensitiveParameter] public ?string $username,
        #[SensitiveParameter] public ?string $password,
    ) {}

    public function equals(self $other): bool
    {
        return $this->username === $other->username && $this->password === $other->password;
    }

    /**
     * The values the output redactor must hide: the password. The user name stays out of every output by construction
     * (command lines name a placeholder); registering it would also redact ordinary words such as `smtp` or `apikey`,
     * which are common SMTP user names, wherever they appear.
     *
     * @return list<string>
     */
    public function secrets(): array
    {
        return $this->password === null || $this->password === '' ? [] : [$this->password];
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['credentials' => '[redacted]'];
    }
}
