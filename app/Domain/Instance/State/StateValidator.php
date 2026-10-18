<?php

declare(strict_types=1);

namespace App\Domain\Instance\State;

use App\Domain\Instance\Docker\ComposeProject;

/**
 * Type and grammar checks of the keys InstanceState knows. Unknown keys are not checked: they belong to a newer
 * minor version and are carried through unchanged. Every value a renderer reads (URL, digests, settings) must
 * pass InputGrammar here too, so a hand-edited or hostile state file fails before anything is rendered (U3).
 */
final class StateValidator
{
    /**
     * @param  array<string, mixed>  $document
     * @param  array{uuid: string, slug: string, digest: string, recipient: string}  $patterns
     *
     * @throws InvalidState
     */
    public static function validate(array $document, array $patterns): void
    {
        self::pattern($document['uuid'] ?? null, $patterns['uuid'], 'uuid');
        self::pattern($document['slug'] ?? null, $patterns['slug'], 'slug');
        self::pattern($document['project'] ?? null, ComposeProject::NAME_PATTERN, 'project');
        self::check(is_string($document['path'] ?? null) && str_starts_with($document['path'], '/'), 'path must be absolute');
        self::check(in_array($document['mode'] ?? null, InstanceState::MODES, true), 'mode is unknown');
        self::owner($document['owner'] ?? null);
        self::url($document['url'] ?? null);
        self::check(($document['version'] ?? null) === null || is_string($document['version']), 'version must be a string');
        self::digests($document['digests'] ?? null, $patterns['digest'], 'digests');
        self::previous($document['previous'] ?? null, $patterns['digest']);
        self::list($document['recipients'] ?? [], $patterns['recipient'], 'recipients');
        self::settings($document['settings'] ?? []);
        $history = $document['history'] ?? [];
        self::check(is_array($history) && array_is_list($history), 'history must be a list');
    }

    private static function owner(mixed $owner): void
    {
        if (! is_array($owner)) {
            self::fail('owner is missing');
        }
        foreach (['host_uid', 'host_gid', 'container_uid'] as $key) {
            self::check(is_int($owner[$key] ?? null) && $owner[$key] >= 0, "owner.{$key} must be a non-negative integer");
        }
    }

    /** http(s) URL without userinfo, query or unsafe characters (InputGrammar). */
    private static function url(mixed $url): void
    {
        self::check($url === null || InputGrammar::isUrl($url), 'url must be an http(s) URL without credentials');
    }

    private static function settings(mixed $settings): void
    {
        self::check(is_array($settings) && ($settings === [] || ! array_is_list($settings)), 'settings must be an object');
        foreach ($settings as $key => $value) {
            $problem = InputGrammar::settingProblem((string) $key, $value);
            $label = preg_match('/^[a-z][a-z0-9_]{0,62}$/D', (string) $key) === 1 ? $key : '(invalid name)';
            self::check($problem === null, "settings.{$label} is invalid: {$problem}");
        }
    }

    private static function digests(mixed $digests, string $pattern, string $label): void
    {
        if (! is_array($digests) || $digests !== [] && array_is_list($digests)) {
            self::fail("{$label} must be an object");
        }
        foreach ($digests as $role => $reference) {
            self::check(preg_match('/^[a-z0-9][a-z0-9_-]*$/D', (string) $role) === 1, "{$label} has an invalid role");
            self::pattern($reference, $pattern, "{$label}.{$role}");
        }
    }

    private static function previous(mixed $previous, string $digestPattern): void
    {
        if ($previous === null) {
            return;
        }
        if (! (is_array($previous) && is_string($previous['version'] ?? null) && is_string($previous['compose'] ?? null))) {
            self::fail('previous must hold version, digests and compose');
        }
        self::digests($previous['digests'] ?? null, $digestPattern, 'previous.digests');
    }

    private static function list(mixed $values, string $pattern, string $label): void
    {
        if (! is_array($values) || ! array_is_list($values)) {
            self::fail("{$label} must be a list");
        }
        foreach ($values as $value) {
            self::pattern($value, $pattern, $label);
        }
    }

    private static function pattern(mixed $value, string $pattern, string $label): void
    {
        self::check(is_string($value) && preg_match($pattern, $value) === 1, "{$label} is invalid");
    }

    private static function check(bool $condition, string $problem): void
    {
        if (! $condition) {
            self::fail($problem);
        }
    }

    private static function fail(string $problem): never
    {
        throw new InvalidState("The instance state is invalid: {$problem}.");
    }
}
