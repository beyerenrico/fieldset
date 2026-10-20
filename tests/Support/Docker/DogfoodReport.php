<?php

declare(strict_types=1);

namespace Tests\Support\Docker;

use RuntimeException;

/**
 * Helpers for the dogfooding journey (plan U22, ADR 0091): Playwright's working directory, the instance's catcher login,
 * and a summary of Playwright's JSON report for the evidence (per spec file; skipped tests with their reason; failures
 * with their first error line).
 */
final class DogfoodReport
{
    /**
     * A working directory for Playwright outside the checkout: the specs write screenshots to relative paths under
     * docs/agent/milestones/evidence/M0–M8 and their sessions to storage/playwright-auth, which then land here instead of
     * in the committed evidence. `tests` links back to the checkout for the fixture files the specs read by relative path.
     */
    public static function workingDirectory(string $directory): string
    {
        foreach (glob(TestImage::repository().'/docs/agent/milestones/evidence/M*', GLOB_ONLYDIR) ?: [] as $milestone) {
            self::directory($directory.'/docs/agent/milestones/evidence/'.basename($milestone));
        }
        self::directory($directory.'/storage/playwright-auth');
        if (! is_link($directory.'/tests')) {
            symlink(TestImage::repository().'/tests', $directory.'/tests');
        }

        return $directory;
    }

    /** `user:password` of the instance's catcher UI (env/mailpit.env MP_UI_AUTH), for MAILPIT_AUTH; never printed. */
    public static function catcherLogin(string $instance): string
    {
        $env = (string) @file_get_contents($instance.'/env/mailpit.env');
        if (preg_match("/^MP_UI_AUTH='?([^'\\n]+)'?$/m", $env, $match) !== 1) {
            throw new RuntimeException("{$instance}/env/mailpit.env holds no catcher login (MP_UI_AUTH).");
        }

        return $match[1];
    }

    /**
     * @return array{stats: array<string, mixed>, files: array<string, array{passed: int, skipped: int, failed: int}>, passed: list<string>, skipped: list<array{test: string, reason: string}>, failed: list<array{test: string, error: string}>}
     */
    public static function summarize(string $json): array
    {
        $report = json_decode($json, true);
        $summary = ['stats' => is_array($report) && is_array($report['stats'] ?? null) ? $report['stats'] : [], 'files' => [], 'passed' => [], 'skipped' => [], 'failed' => []];
        foreach (is_array($report) && is_array($report['suites'] ?? null) ? $report['suites'] : [] as $suite) {
            self::suite($summary, (string) ($suite['file'] ?? $suite['title'] ?? '?'), $suite, []);
        }
        if (is_array($report) && is_array($report['errors'] ?? null)) {
            foreach ($report['errors'] as $error) {
                $summary['failed'][] = ['test' => '(run)', 'error' => self::firstLine($error)];
            }
        }
        ksort($summary['files']);

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $suite
     * @param  list<string>  $path  describe titles above this suite
     */
    private static function suite(array &$summary, string $file, array $suite, array $path): void
    {
        foreach (is_array($suite['specs'] ?? null) ? $suite['specs'] : [] as $spec) {
            foreach (is_array($spec['tests'] ?? null) ? $spec['tests'] : [] as $test) {
                self::test($summary, $file, implode(' › ', [$file, ...$path, (string) ($spec['title'] ?? '?')]), $test);
            }
        }
        foreach (is_array($suite['suites'] ?? null) ? $suite['suites'] : [] as $child) {
            self::suite($summary, $file, $child, [...$path, (string) ($child['title'] ?? '?')]);
        }
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $test
     */
    private static function test(array &$summary, string $file, string $title, array $test): void
    {
        $summary['files'][$file] ??= ['passed' => 0, 'skipped' => 0, 'failed' => 0];
        $status = (string) ($test['status'] ?? '');
        if ($status === 'skipped') {
            $annotation = array_find(is_array($test['annotations'] ?? null) ? $test['annotations'] : [], static fn (mixed $a): bool => is_array($a) && ($a['type'] ?? null) === 'skip');
            $summary['files'][$file]['skipped']++;
            $summary['skipped'][] = ['test' => $title, 'reason' => is_array($annotation) ? (string) ($annotation['description'] ?? '') : ''];
        } elseif ($status === 'expected' || $status === 'flaky') {
            $summary['files'][$file]['passed']++;
            $summary['passed'][] = $title;
        } else {
            $results = is_array($test['results'] ?? null) ? $test['results'] : [];
            $last = $results === [] ? [] : $results[array_key_last($results)];
            $summary['files'][$file]['failed']++;
            $summary['failed'][] = ['test' => $title, 'error' => self::firstLine(is_array($last) ? ($last['error'] ?? []) : [])];
        }
    }

    private static function firstLine(mixed $error): string
    {
        $message = is_array($error) ? (string) ($error['message'] ?? '') : '';
        $clean = (string) preg_replace('/\e\[[0-9;]*m/', '', $message);

        return mb_substr(strtok($clean, "\n") ?: '', 0, 300);
    }

    private static function directory(string $path): void
    {
        if (! is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }
}
