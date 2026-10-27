<?php

declare(strict_types=1);

namespace App\Domain\Instance\Doctor\Checks;

use App\Domain\Instance\Docker\OwnerSource;
use App\Domain\Instance\Doctor\CheckResult;
use App\Domain\Instance\Doctor\DoctorCheck;
use App\Domain\Instance\Doctor\DoctorContext;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\MeasuredOwner;

/**
 * Who can change what the socket holder trusts (KTD2, ADR 0070): the instance directory, the state, the compose
 * file and the fieldset shim must not be writable by group or others, and must belong to the instance owner as
 * containers see it. Anyone who can edit them can make fieldset start containers of their choosing.
 *
 * Since 2.1.1 (U4, KTD3) the owner comes from the shim's measurement in this run, compared with the one saved with the
 * instance; `details` say which owner was saved, which was measured and who measured (`owner_source`: measured, saved,
 * rules). A differing measurement fails as a mismatch whose fix is `fieldset repair-ownership`, never a chown (the files
 * may well belong to the user already). When the measurement could not run (`rules`) the owner is unknown: status
 * `unknown`, never a mismatch; group- or other-writable files fail in every case.
 */
final readonly class PermissionsCheck implements DoctorCheck
{
    /** The warning code of an owner that could not be measured. */
    public const string UNMEASURED = MeasuredOwner::NOT_MEASURED;

    private const array FILES = [
        '' => 'the instance directory',
        InstanceDirectory::STATE_FILE => 'state.json',
        InstanceDirectory::COMPOSE_FILE => 'compose.yaml',
        'fieldset' => 'the fieldset command',
    ];

    public function key(): string
    {
        return 'permissions';
    }

    public function run(DoctorContext $context): CheckResult
    {
        $owner = $context->ownerUid();
        [$modes, $owners] = $this->inspect($context, $owner);
        $details = $this->details($context, $owner);
        $source = $context->facts->ownerSource;
        if ($modes === [] && $source === OwnerSource::Rules) {
            return CheckResult::unknown($this->key(), $this->unmeasured($context, $owner), 'Run `fieldset doctor` again once Docker can start containers: fieldset measures the owner with a short-lived container of its image.', $details, self::UNMEASURED);
        }
        if ($context->ownerMismatch()) {
            return CheckResult::fail($this->key(), $this->sentence([...array_keys($modes), $this->mismatch($context)]), $this->chmod($modes).$this->repair($context), $details);
        }
        $owners = $source === OwnerSource::Rules ? [] : $owners;
        if ($modes !== [] || $owners !== []) {
            return CheckResult::fail($this->key(), $this->sentence([...array_keys($modes), ...array_keys($owners)]), rtrim($this->chmod($modes).$this->chown($context, $owners)), $details);
        }

        return CheckResult::ok($this->key(), "Only the instance owner (UID {$owner} inside containers, ".$this->source($source).') can change the instance files.', $details);
    }

    /** @return array{0: array<string, string>, 1: array<string, string>} mode and owner problems, each problem => path */
    private function inspect(DoctorContext $context, int $owner): array
    {
        $modes = [];
        $owners = [];
        foreach (self::FILES as $relative => $label) {
            $path = $relative === '' ? $context->directory->root : $context->directory->root.'/'.$relative;
            $stat = $context->probes->stat($path);
            if ($stat === null) {
                continue;
            }
            if (($stat['mode'] & 0022) !== 0) {
                $modes[sprintf('%s is writable by group or others (mode %04o)', $label, $stat['mode'])] = $path;
            }
            if ($stat['uid'] !== $owner) {
                $owners["{$label} belongs to UID {$stat['uid']}, not the instance owner (UID {$owner} inside containers)"] = $path;
            }
        }

        return [$modes, $owners];
    }

    /** @return array<string, mixed> */
    private function details(DoctorContext $context, int $owner): array
    {
        return [
            'owner_uid' => $owner, 'saved_owner_uid' => $context->savedOwnerUid(), 'measured_owner_uid' => $context->measuredOwnerUid(),
            'owner_source' => $context->facts->ownerSource?->value, 'owner_mismatch' => $context->ownerMismatch(),
        ];
    }

    private function unmeasured(DoctorContext $context, int $owner): string
    {
        $saved = $context->savedOwnerUid();

        return "Could not measure who owns the instance files inside containers, so fieldset assumed UID {$owner} by its rules"
            .($saved === null ? '' : "; the instance was set up for UID {$saved}").'.';
    }

    private function mismatch(DoctorContext $context): string
    {
        return "the instance was set up for UID {$context->savedOwnerUid()} inside containers (saved with the instance), but containers now see your files as UID {$context->measuredOwnerUid()} (measured just now): Docker maps user IDs differently than when the instance was set up";
    }

    private function repair(DoctorContext $context): string
    {
        return "Run `fieldset repair-ownership`: it gives the instance files from UID {$context->savedOwnerUid()} to UID {$context->measuredOwnerUid()} as containers see them and saves the new owner; modes and Docker volumes stay as they are.";
    }

    /** @param  array<string, string>  $modes */
    private function chmod(array $modes): string
    {
        return $modes === [] ? '' : 'Run as the instance owner: chmod go-w '.implode(' ', array_unique($modes)).'. ';
    }

    /**
     * A chown only where it helps: the owner is known (measured or saved) and the files belong to someone else. With a launcher
     * before 2.1.1 the owner was never measured, so the files may be right and the expectation wrong.
     *
     * @param  array<string, string>  $owners
     */
    private function chown(DoctorContext $context, array $owners): string
    {
        if ($owners === []) {
            return 'Then run the command again.';
        }
        $paths = implode(' ', array_unique($owners));
        if (! $context->facts->ownerSource instanceof OwnerSource) {
            return "Run `fieldset doctor` again with the fieldset script of this release, which measures who owns the files inside containers; if they really belong to another user, give them back to yours (sudo chown -h <you> {$paths}).";
        }

        return "Give them back to your user: sudo chown -h {$context->facts->callerUid()}:{$context->facts->callerGid()} {$paths}, then run the command again.";
    }

    private function source(?OwnerSource $source): string
    {
        return match ($source) {
            OwnerSource::Measured => 'measured just now',
            OwnerSource::Saved => 'saved with the instance',
            OwnerSource::Rules => 'by the product-name rules',
            null => 'as the launcher reported it',
        };
    }

    /** @param  list<string>  $problems */
    private function sentence(array $problems): string
    {
        $text = implode('; ', $problems).'.';

        return str_starts_with($text, 'the ') ? ucfirst($text) : $text;
    }
}
