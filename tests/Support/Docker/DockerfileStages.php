<?php

declare(strict_types=1);

namespace Tests\Support\Docker;

use RuntimeException;

/**
 * Splits a Dockerfile into its build stages (instructions with line continuations joined), so tests can
 * assert what each stage, and above all the final one, contains.
 */
final readonly class DockerfileStages
{
    /** @param array<string, list<string>> $stages stage name => instructions, in file order */
    private function __construct(public string $text, private array $stages) {}

    public static function fromFile(string $path): self
    {
        $text = (string) file_get_contents($path);
        $joined = (string) preg_replace('/\\\\\n\s*/', ' ', $text);
        $stages = [];
        $current = null;
        foreach (explode("\n", $joined) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // FROM [--platform=…] <image> [AS <name>]
            if (preg_match('/^FROM\s+(?:--\S+\s+)*(\S+)(?:\s+AS\s+(\S+))?/i', $line, $match) === 1) {
                $current = $match[2] ?? $match[1];
                $stages[$current] = [];
            }

            if ($current !== null) {
                $stages[$current][] = $line;
            }
        }

        return new self($text, $stages);
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->stages);
    }

    public function stage(string $name): string
    {
        if (! array_key_exists($name, $this->stages)) {
            throw new RuntimeException(sprintf('The Dockerfile has no stage "%s".', $name));
        }

        return implode("\n", $this->stages[$name]);
    }

    public function finalStageName(): string
    {
        $names = $this->names();

        return $names[count($names) - 1];
    }

    public function finalStage(): string
    {
        return $this->stage($this->finalStageName());
    }
}
