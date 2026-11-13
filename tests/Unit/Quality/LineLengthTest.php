<?php

declare(strict_types=1);

/*
 * No PHP line in app/ is longer than this many characters. 300 is today's value because the tree was wrapped to it
 * (553 lines exceed 200, so a lower limit is a follow-up). Pint has no line-length rule and a sniffer would be a new
 * dependency for one rule, so the limit is enforced here (plan KTD15).
 */
const MAX_PHP_LINE_LENGTH = 300;

/** @return list<string> "file:line (length)" for every line of app/**.php above the limit */
function overlongPhpLines(string $root): array
{
    $found = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        foreach (file($file->getPathname(), FILE_IGNORE_NEW_LINES) ?: [] as $index => $line) {
            $length = mb_strlen($line);
            if ($length > MAX_PHP_LINE_LENGTH) {
                $found[] = substr($file->getPathname(), strlen(dirname($root)) + 1).':'.($index + 1)." ({$length} characters)";
            }
        }
    }
    sort($found);

    return $found;
}

it('keeps every PHP line in app/ within the limit', function (): void {
    expect(overlongPhpLines(dirname(__DIR__, 3).'/app'))->toBe([]);
});
