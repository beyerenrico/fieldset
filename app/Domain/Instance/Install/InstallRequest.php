<?php

declare(strict_types=1);

namespace App\Domain\Instance\Install;

use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\Release\ReleaseException;
use App\Domain\Instance\Release\ReleaseVersion;
use App\Domain\Instance\State\InputGrammar;

/**
 * The inputs of `fsa install` (§7, R26): the flags given on the command line, validated, plus their defaults.
 * Only explicit flags count when comparing with an earlier run: an omitted flag never conflicts.
 *
 * - App flags (organization, owner, locale) are consumed by the `app_install` phase: corrections are accepted
 *   until it has run.
 * - Preflight flags (version, port, domain) are consumed by the `doctor` phase.
 */
final readonly class InstallRequest
{
    public const array APP_FLAGS = ['organization', 'owner_email', 'owner_name', 'locale'];

    public const array PREFLIGHT_FLAGS = ['version', 'port', 'domain'];

    public const array REQUIRED = ['organization', 'owner_email'];

    public const array LOCALES = ['de', 'en'];

    public const string DEFAULT_LOCALE = 'de';

    /** Request key => command-line flag. */
    public const array FLAGS = [
        'organization' => '--org', 'owner_email' => '--owner-email', 'owner_name' => '--owner-name', 'locale' => '--locale',
        'version' => '--version', 'port' => '--port', 'domain' => '--domain',
    ];

    /** Command option => request key (the fsa wrapper passes --version as --release, ADR 0072). */
    private const array OPTIONS = [
        'org' => 'organization', 'owner-email' => 'owner_email', 'owner-name' => 'owner_name', 'locale' => 'locale',
        'release' => 'version', 'port' => 'port', 'domain' => 'domain',
    ];

    private const int MAX_NAME = 120;

    /** @param  array<string, string|int>  $explicit  request key => validated value */
    private function __construct(private array $explicit) {}

    /**
     * @param  array<string, mixed>  $options  command options by name
     *
     * @throws InvalidInput for a malformed value (exit 2)
     */
    public static function fromOptions(array $options): self
    {
        $request = new self([]);
        foreach (self::OPTIONS as $option => $key) {
            $value = $options[$option] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $request = $request->with($key, $value);
            }
        }

        return $request;
    }

    /** @throws InvalidInput */
    public function with(string $key, string $value): self
    {
        return new self([...$this->explicit, $key => $this->validate($key, trim($value))]);
    }

    /** @return array<string, string|int> */
    public function explicit(): array
    {
        return $this->explicit;
    }

    /** @return list<string> required request keys that are not set */
    public function missing(): array
    {
        return array_values(array_filter(self::REQUIRED, fn (string $key): bool => ! array_key_exists($key, $this->explicit)));
    }

    /**
     * This request over the values an earlier run recorded (resume): what was given then still applies.
     *
     * @param  array<string, mixed>  $recorded
     */
    public function over(array $recorded): self
    {
        $known = array_filter(
            array_intersect_key($recorded, self::FLAGS),
            static fn (mixed $value): bool => is_string($value) || is_int($value),
        );

        return new self([...$known, ...$this->explicit]);
    }

    /**
     * Explicit flags whose value differs from what was already applied. A key absent from `$applied` was not
     * applied yet and never conflicts; a null value was applied as "not set".
     *
     * @param  array<string, string|int|null>  $applied
     * @return list<string> command-line flags
     */
    public function changedFlags(array $applied): array
    {
        $changed = array_filter(
            $this->explicit,
            static fn (string|int $value, string $key): bool => array_key_exists($key, $applied) && (string) $applied[$key] !== (string) $value,
            ARRAY_FILTER_USE_BOTH,
        );

        return array_values(array_map(static fn (string $key): string => self::FLAGS[$key], array_keys($changed)));
    }

    public function organization(): string
    {
        return $this->string('organization');
    }

    public function ownerEmail(): string
    {
        return $this->string('owner_email');
    }

    /** The owner's name; without --owner-name the part of the email before the @. */
    public function ownerName(): string
    {
        return is_string($this->explicit['owner_name'] ?? null) ? $this->explicit['owner_name'] : explode('@', $this->ownerEmail())[0];
    }

    public function locale(): string
    {
        return is_string($this->explicit['locale'] ?? null) ? $this->explicit['locale'] : self::DEFAULT_LOCALE;
    }

    public function port(): ?int
    {
        return is_int($this->explicit['port'] ?? null) ? $this->explicit['port'] : null;
    }

    public function version(): ?string
    {
        return is_string($this->explicit['version'] ?? null) ? $this->explicit['version'] : null;
    }

    public function domain(): ?string
    {
        return is_string($this->explicit['domain'] ?? null) ? $this->explicit['domain'] : null;
    }

    /**
     * What the install journal records: the app flags with their defaults, the preflight flags as given.
     *
     * @return array<string, string|int|null>
     */
    public function toContext(): array
    {
        return [
            'organization' => $this->explicit['organization'] ?? null,
            'owner_email' => $this->explicit['owner_email'] ?? null,
            'owner_name' => isset($this->explicit['owner_email']) ? $this->ownerName() : null,
            'locale' => $this->locale(),
            'version' => $this->version(),
            'port' => $this->port(),
            'domain' => $this->domain(),
        ];
    }

    private function string(string $key): string
    {
        $value = $this->explicit[$key] ?? null;

        return is_string($value) ? $value : throw new InstanceException("The install has no value for {$key}.");
    }

    /** @throws InvalidInput */
    private function validate(string $key, string $value): string|int
    {
        $flag = self::FLAGS[$key] ?? throw new InstanceException("Unknown install input [{$key}].");
        $valid = match ($key) {
            'organization', 'owner_name' => $value !== '' && mb_strlen($value) <= self::MAX_NAME && preg_match('/[\x00-\x1F\x7F]/u', $value) !== 1 && preg_match('//u', $value) === 1,
            'owner_email' => InputGrammar::isEmail(strtolower($value)),
            'locale' => in_array($value, self::LOCALES, true),
            'port' => ctype_digit($value) && InputGrammar::isPort((int) $value),
            'version' => $this->isVersion($value),
            default => InputGrammar::isHost(strtolower($value)) && ! InputGrammar::isIp($value) && str_contains($value, '.'),
        };
        if (! $valid) {
            throw new InvalidInput("Invalid value for {$flag}.", 'invalid_flag', $this->hint($key));
        }

        return match ($key) {
            'port' => (int) $value,
            'owner_email', 'domain' => strtolower($value),
            default => $value,
        };
    }

    private function isVersion(string $value): bool
    {
        try {
            ReleaseVersion::parse($value);

            return true;
        } catch (ReleaseException) {
            return false;
        }
    }

    private function hint(string $key): string
    {
        return match ($key) {
            'organization', 'owner_name' => 'Use at most 120 characters without line breaks.',
            'owner_email' => 'Pass an e-mail address such as owner@example.com.',
            'locale' => 'Use de or en.',
            'port' => 'Use a port number between 1 and 65535.',
            'version' => 'Use a release version such as 2.0.0 or 2.0.0-rc.1.',
            default => 'Use a domain name such as forms.example.com.',
        };
    }
}
