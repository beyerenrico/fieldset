<?php

declare(strict_types=1);

namespace App\Domain\Instance\Upgrade;

use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\Release\ReleaseException;
use App\Domain\Instance\Release\ReleaseVersion;

/**
 * What `fsa upgrade` was asked for (R33, KTD19, plan U18, ADR 0086): `--to` a version (the newest release otherwise),
 * `--major` to cross a major version, `--drop-jobs` to save and drop the jobs a bounded drain leaves behind, `--resume`
 * to continue an interrupted upgrade. Nothing here is secret; the journal keeps it as its context.
 */
final readonly class UpgradeRequest
{
    public function __construct(
        public ?string $to = null,
        public bool $major = false,
        public bool $dropJobs = false,
        public bool $resume = false,
        public int $actorUid = 0,
    ) {}

    /**
     * @throws InvalidInput exit 2 for a `--to` that is no release version
     */
    public static function fromOptions(mixed $to, bool $major, bool $dropJobs, bool $resume, int $actorUid): self
    {
        $version = is_string($to) && trim($to) !== '' ? trim($to) : null;
        if ($version !== null) {
            try {
                $version = (string) ReleaseVersion::fromInput($version);
            } catch (ReleaseException) {
                throw new InvalidInput("--to must name a release version such as 2.0.1 or 2.1.0-rc.1, not {$version}.", 'invalid_version', 'Run `fsa version` for the newest known release.');
            }
        }

        return new self($version, $major, $dropJobs, $resume, $actorUid);
    }

    /** @return array<string, string|bool|null> the journal context */
    public function toContext(): array
    {
        return ['to' => $this->to, 'major' => $this->major, 'drop_jobs' => $this->dropJobs];
    }

    /** @return list<string> the flags the detached supervisor gets (KTD5) */
    public function flags(): array
    {
        return [
            ...($this->to === null ? [] : ['--to', $this->to]),
            ...($this->major ? ['--major'] : []),
            ...($this->dropJobs ? ['--drop-jobs'] : []),
            ...($this->resume ? ['--resume'] : []),
        ];
    }
}
