<?php

declare(strict_types=1);

use Tests\Support\Docker\DogfoodReport;

/*
 * The dogfooding journey (ADR 0091) keeps one error line per failure in its evidence; its assertion message carries the
 * whole message, so a CI failure can be read from the log.
 */

function dogfoodReportJson(string $message): string
{
    return (string) json_encode(['stats' => ['unexpected' => 1], 'suites' => [[
        'file' => 'm7-builder-v2.spec.ts',
        'specs' => [['title' => 'passes', 'tests' => [['status' => 'expected', 'results' => [['status' => 'passed']]]]]],
        'suites' => [['title' => 'nested', 'specs' => [['title' => 'drags', 'tests' => [['status' => 'unexpected', 'results' => [['status' => 'failed', 'error' => ['message' => $message]]]]]]]]],
    ]]]);
}

it('keeps the first error line in the summary and the whole message, without colour codes, in the details', function (): void {
    $json = dogfoodReportJson("\e[31mError: expect(locator).toHaveAttribute(expected) failed\e[39m\n\nExpected: \"date\"\nReceived: \"text\"\nCall log:\n  - waiting for locator");

    expect(DogfoodReport::summarize($json)['failed'])->toBe([['test' => 'm7-builder-v2.spec.ts › nested › drags', 'error' => 'Error: expect(locator).toHaveAttribute(expected) failed']])
        ->and(DogfoodReport::failureDetails($json))->toBe("drags:\nError: expect(locator).toHaveAttribute(expected) failed\n\nExpected: \"date\"\nReceived: \"text\"\nCall log:\n  - waiting for locator");
});

it('redacts a one-time sign-in link in the details', function (): void {
    $details = DogfoodReport::failureDetails(dogfoodReportJson('Error: page.goto: net::ERR at http://localhost:8080/login-link#abcdef0123456789abcdef0123456789'));

    expect($details)->not->toContain('abcdef0123456789abcdef0123456789')->toContain('drags:');
});

it('lists every result by start time with its offset, duration, worker and status for the CI log artifact', function (): void {
    $json = (string) json_encode(['stats' => ['startTime' => '2026-10-22T00:42:03.700Z'], 'suites' => [[
        'file' => 'm8-csp.spec.ts',
        'specs' => [['title' => 'classic', 'tests' => [['status' => 'unexpected', 'results' => [
            ['workerIndex' => 1, 'status' => 'failed', 'startTime' => '2026-10-22T00:43:00.000Z', 'duration' => 30500],
            ['workerIndex' => 2, 'status' => 'passed', 'startTime' => '2026-10-22T00:43:40.000Z', 'duration' => 900],
        ]]]]],
        'suites' => [['title' => 'designer', 'specs' => [['title' => 'first', 'tests' => [['status' => 'expected', 'results' => [
            ['workerIndex' => 0, 'status' => 'passed', 'startTime' => '2026-10-22T00:42:10.000Z', 'duration' => 1200],
        ]]]]]]],
    ]]]);

    expect(DogfoodReport::timeline($json))->toBe([
        '+6.3s 1.2s w0 passed m8-csp.spec.ts › designer › first',
        '+56.3s 30.5s w1 failed m8-csp.spec.ts › classic',
        '+96.3s 0.9s w2 passed m8-csp.spec.ts › classic',
    ])->and(DogfoodReport::timeline(''))->toBe([]);
});

it('is empty for a report without failures or no report at all', function (): void {
    expect(DogfoodReport::failureDetails((string) json_encode(['suites' => []])))->toBe('')
        ->and(DogfoodReport::failureDetails(''))->toBe('');
});
