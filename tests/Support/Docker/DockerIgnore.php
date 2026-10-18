<?php

declare(strict_types=1);

namespace Tests\Support\Docker;

/**
 * Evaluates a .dockerignore file the way the builder does (moby/patternmatcher): rules apply in order,
 * the last matching rule wins, `!` re-includes, and a rule that matches a parent directory also matches
 * everything below it.
 */
final readonly class DockerIgnore
{
    /** @param list<array{regex: string, exclude: bool}> $rules */
    private function __construct(private array $rules) {}

    public static function fromFile(string $path): self
    {
        $rules = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $exclude = ! str_starts_with($line, '!');
            $pattern = trim(ltrim($exclude ? $line : substr($line, 1), '/'), '/');
            $rules[] = ['regex' => self::toRegex($pattern), 'exclude' => $exclude];
        }

        return new self($rules);
    }

    /** Whether the file at this context-relative path reaches the builder. */
    public function includes(string $path): bool
    {
        $excluded = false;
        foreach ($this->rules as $rule) {
            if ($this->matchesOrParentMatches($rule['regex'], $path)) {
                $excluded = $rule['exclude'];
            }
        }

        return ! $excluded;
    }

    private function matchesOrParentMatches(string $regex, string $path): bool
    {
        $parts = explode('/', $path);
        for ($depth = count($parts); $depth > 0; $depth--) {
            if (preg_match($regex, implode('/', array_slice($parts, 0, $depth))) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function toRegex(string $pattern): string
    {
        $regex = strtr(preg_quote($pattern, '#'), [
            '\*\*/' => '(?:.*/)?',
            '\*\*' => '.*',
            '\*' => '[^/]*',
            '\?' => '[^/]',
        ]);

        return '#^'.$regex.'$#';
    }
}
