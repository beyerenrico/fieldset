<?php

declare(strict_types=1);

namespace App\Domain\Instance\Runtime;

/**
 * What runtime.json tells the `/platform` instance page about the stack (R47, plan U20, ADR 0089): the installed
 * release, the mode, the image digests and where the mail catcher's UI is. Display data only: a block that is missing or
 * does not match this grammar counts as absent (the page then says "unknown"), and nothing security-relevant depends
 * on it, so unlike the URL and the gate it never fails the whole file.
 *
 * - version: a semantic version; mode: local, proxy or domain;
 * - digests: image role => `repository@sha256:<64 hex>` (at most 20);
 * - catcher_url: an http(s) URL or null (no catcher runs).
 */
final readonly class InstanceFacts
{
    public const string VERSION = '/^\d{1,4}\.\d{1,4}\.\d{1,4}(?:-[0-9A-Za-z.-]{1,40})?$/D';

    private const array MODES = ['local', 'proxy', 'domain'];

    private const string ROLE = '/^[a-z][a-z0-9_-]{0,31}$/D';

    private const string REFERENCE = '/^[a-z0-9][a-z0-9.\/:_-]{0,254}@sha256:[0-9a-f]{64}$/D';

    private const int MAX_DIGESTS = 20;

    /** @param  array<string, string>  $digests  image role => pinned reference */
    public function __construct(
        public ?string $version,
        public ?string $mode,
        public array $digests,
        public ?string $catcherUrl,
    ) {}

    /** The `instance` block, or null when it is missing or malformed. */
    public static function fromDocument(mixed $block): ?self
    {
        if (! is_array($block)) {
            return null;
        }
        $version = $block['version'] ?? null;
        $mode = $block['mode'] ?? null;
        $catcher = $block['catcher_url'] ?? null;
        $digests = self::digests($block['digests'] ?? []);
        if (($version !== null && (! is_string($version) || preg_match(self::VERSION, $version) !== 1))
            || ($mode !== null && ! in_array($mode, self::MODES, true))
            || ($catcher !== null && ! RuntimeDocument::isUrl($catcher))
            || $digests === null) {
            return null;
        }

        return new self($version, $mode, $digests, $catcher);
    }

    /** @return array<string, string>|null */
    private static function digests(mixed $digests): ?array
    {
        if (! is_array($digests) || count($digests) > self::MAX_DIGESTS) {
            return null;
        }
        $valid = [];
        foreach ($digests as $role => $reference) {
            if (! is_string($role) || preg_match(self::ROLE, $role) !== 1 || ! is_string($reference) || preg_match(self::REFERENCE, $reference) !== 1) {
                return null;
            }
            $valid[$role] = $reference;
        }

        return $valid;
    }
}
