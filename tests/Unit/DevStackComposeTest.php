<?php

declare(strict_types=1);

/*
 * The dev and CI stack (compose.yaml): app, queue and scheduler share the storage volume, and Docker's first copy of the
 * image's files into a new volume fails when several containers create it at the same moment ("failed to mkdir
 * …/_data/private: file exists", CI run 39724314998). Queue and scheduler are created after app and start once it is
 * healthy, after its migrations (RUN_MIGRATIONS), as in the stack file (StackFile).
 */

function devStackService(string $name): string
{
    preg_match('/^  '.$name.':\n(.*?)(?=^  [a-z][a-z0-9-]*:\n|^\S|\z)/ms', (string) file_get_contents(base_path('compose.yaml')), $match);

    return $match[1] ?? '';
}

it('creates queue and scheduler after a healthy app, keeping their other dependencies', function (string $service): void {
    expect(devStackService($service))->toContain(
        "    <<: *app\n",
        "    depends_on:\n"
        ."      postgres:\n        condition: service_healthy\n"
        ."      redis:\n        condition: service_healthy\n"
        ."      app:\n        condition: service_healthy\n",
    );
})->with(['queue', 'scheduler']);

it('starts app without waiting for another app role', function (): void {
    expect(devStackService('app'))->not->toContain('depends_on');
});
