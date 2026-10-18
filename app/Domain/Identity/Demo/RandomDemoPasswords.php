<?php

declare(strict_types=1);

namespace App\Domain\Identity\Demo;

use Illuminate\Support\Str;

/**
 * One random password per demo user (R29, ADR 0081): 24 letters and digits from the CSPRNG (Str::password uses
 * random_int), easy to copy from a terminal. The passwords handed out are remembered for the one-time output and
 * never logged; deliberately mutable, one instance per seeding.
 */
final class RandomDemoPasswords implements DemoPasswordSource
{
    public const int LENGTH = 24;

    /** @var array<string, string> email => password */
    private array $issued = [];

    public function passwordFor(string $email): string
    {
        return $this->issued[$email] = self::generate();
    }

    public static function generate(): string
    {
        return Str::password(self::LENGTH, letters: true, numbers: true, symbols: false, spaces: false);
    }

    /** @return array<string, string> email => password, in the order they were handed out */
    public function issued(): array
    {
        return $this->issued;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['issued' => '[redacted]'];
    }
}
