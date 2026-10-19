<?php

declare(strict_types=1);

namespace App\Domain\Instance\Reset;

use App\Domain\Instance\Install\InstallRecord;
use App\Domain\Instance\Install\InstallRequest;
use App\Domain\Instance\Install\RecordDigest;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Lifecycle\OneOffResult;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\State\InstanceState;
use SensitiveParameter;

/**
 * Who and what the fresh instance starts with after `fsa reset` (E9, plan U23, ADR 0088): install's seed phase again,
 * the first organization and its owner. Decided from the live database before anything stops (`instance:reset plan`):
 *
 * - the organization and locale the install record names (plain values), else the oldest real organization's;
 * - the owner: the active platform operator whose e-mail matches the install record's digest (the install owner), else
 *   the operator granted first; none is exit 3 `owner_unknown` (nothing changed);
 * - whether real organizations (not the demo, soft-deleted ones included) exist: then `--no-backup` still writes an
 *   audit export.
 *
 * The owner's e-mail and name are personal: they stay in the open journal as a note (a reset resumed after the
 * database was dropped has nowhere else to read them) and are replaced by digests when the journal is archived.
 */
final readonly class ResetSubject
{
    public const string NOTE = 'subject';

    /** Journal values recorded only as digests once the journal is archived. */
    public const array PERSONAL = ['owner_email', 'owner_name'];

    private function __construct(
        public string $organization,
        public string $locale,
        public string $ownerEmail,
        public ?string $ownerName,
        public int $organizations,
        public int $nonDemo,
    ) {}

    /** @throws InstanceException exit 3 when no owner or organization can be found */
    public static function plan(OneOffResult $plan, InstanceState $state, ?RecordDigest $digest): self
    {
        $data = $plan->data;
        $recorded = InstallRecord::recorded($state) ?? [];
        $operators = array_values(array_filter(is_array($data['operators'] ?? null) ? $data['operators'] : [], static fn (mixed $row): bool => is_array($row) && is_string($row['email'] ?? null) && $row['email'] !== ''));
        $digested = is_string($recorded['owner_email'] ?? null) ? $recorded['owner_email'] : null;
        $owner = array_find($operators, static fn (array $row): bool => $digest instanceof RecordDigest && $digested !== null && $digest->matches($digested, $row['email'])) ?? ($operators[0] ?? null);
        if ($owner === null) {
            throw new InstanceException('No active platform operator can own the fresh instance.', ExitCode::PreflightFailed, 'owner_unknown', 'Grant the operator role with `fsa config set operator-email <email>`, then run `fsa reset` again; nothing was changed.');
        }
        $first = is_array($data['organization'] ?? null) ? $data['organization'] : [];
        $organization = self::text($recorded['organization'] ?? null) ?? self::text($first['name'] ?? null)
            ?? throw new InstanceException('The organization the instance was installed with is unknown.', ExitCode::PreflightFailed, 'organization_unknown', 'Install a new instance with `fsa install` instead; nothing was changed.');
        $locale = array_find([$recorded['locale'] ?? null, $first['locale'] ?? null, $owner['locale'] ?? null], static fn (mixed $value): bool => in_array($value, InstallRequest::LOCALES, true));
        $counts = is_array($data['organizations'] ?? null) ? $data['organizations'] : [];

        return self::valid(new self(
            $organization, is_string($locale) ? $locale : InstallRequest::DEFAULT_LOCALE, strtolower($owner['email']), self::text($owner['name'] ?? null),
            is_int($counts['total'] ?? null) ? $counts['total'] : 0, is_int($counts['non_demo'] ?? null) ? $counts['non_demo'] : 0,
        ));
    }

    /**
     * The subject a journal recorded: the plan phase's data plus the note with the owner.
     *
     * @param  array<string, mixed>  $phase
     * @param  array<string, mixed>  $note
     */
    public static function fromJournal(array $phase, #[SensitiveParameter] array $note): self
    {
        $int = static fn (string $key): int => is_int($phase[$key] ?? null) ? $phase[$key] : 0;

        return new self(
            (string) self::text($phase['organization'] ?? null), (string) self::text($phase['locale'] ?? null), (string) self::text($note['owner_email'] ?? null),
            self::text($note['owner_name'] ?? null), $int('organizations'), $int('non_demo'),
        );
    }

    /** Whether the reset must write an audit export: no safety backup, and real organizations' audit trails would go. */
    public function exportNeeded(bool $noBackup): bool
    {
        return $noBackup && $this->nonDemo > 0;
    }

    /** app:install's inputs, through install's own grammar. */
    public function installRequest(): InstallRequest
    {
        return InstallRequest::fromOptions(['org' => $this->organization, 'owner-email' => $this->ownerEmail, 'owner-name' => $this->ownerName, 'locale' => $this->locale]);
    }

    /** @return array<string, string|int> the plan phase's data (nothing personal) */
    public function toPhase(): array
    {
        return ['organization' => $this->organization, 'locale' => $this->locale, 'organizations' => $this->organizations, 'non_demo' => $this->nonDemo];
    }

    /** @return array{owner_email: string, owner_name: string|null} the journal note */
    public function toNote(): array
    {
        return ['owner_email' => $this->ownerEmail, 'owner_name' => $this->ownerName];
    }

    /** A name install's grammar refuses is left out (install then uses the e-mail's local part); anything else is exit 3. */
    private static function valid(self $subject): self
    {
        try {
            $subject->installRequest();

            return $subject;
        } catch (InvalidInput $e) {
            if ($subject->ownerName === null) {
                throw new InstanceException('The fresh instance cannot be seeded with the recorded organization or owner: '.$e->getMessage(), ExitCode::PreflightFailed, 'reset_subject_invalid', 'Install a new instance with `fsa install` instead; nothing was changed.');
            }

            return self::valid(new self($subject->organization, $subject->locale, $subject->ownerEmail, null, $subject->organizations, $subject->nonDemo));
        }
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
