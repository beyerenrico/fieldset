<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Tests\Support\Docs\GuideReferences;

/*
 * ADR 0042's rule, checked (plan U21): every `fsa` command and flag, install.sh flag, `php artisan` command,
 * environment variable and relative link a guide names exists. Guides: README.md and docs/guide/ (the 1.0 archive
 * included: what it names must still exist, or the archive must say otherwise).
 */

/** @return array<string, string> path => contents */
function guideFiles(): array
{
    $files = ['README.md' => (string) file_get_contents(base_path('README.md'))];
    foreach (Finder::create()->files()->in(base_path('docs/guide'))->name('*.md')->sortByName() as $file) {
        $files['docs/guide/'.$file->getRelativePathname()] = $file->getContents();
    }

    return $files;
}

/** Everything the code can read an environment variable from: one string to search. */
function guideCodeCorpus(): string
{
    static $corpus = null;
    if ($corpus === null) {
        $finder = Finder::create()->files()->in(array_map(base_path(...), ['app', 'config', 'bootstrap', 'routes', 'database', 'distribution', 'docker', 'scripts']))
            ->name(['*.php', '*.sh', '*.json', '*.yaml', '*.yml', '*.conf', 'fsa', 'Dockerfile', 'Caddyfile*'])->notPath('cache');
        $corpus = implode("\n", array_map(static fn (SplFileInfo $file): string => $file->getContents(), iterator_to_array($finder, false)));
        foreach (['.env.example', '.env.production.example', 'compose.yaml', 'phpunit.xml'] as $file) {
            $corpus .= "\n".@file_get_contents(base_path($file));
        }
    }

    return $corpus;
}

it('names only fsa commands and flags that exist', function (): void {
    $commands = Artisan::all();
    $problems = [];
    foreach (guideFiles() as $path => $markdown) {
        foreach (GuideReferences::code($markdown) as $code) {
            foreach (GuideReferences::fsaCalls($code) as $call) {
                $command = $commands['fsa:'.$call['command']] ?? null;
                if ($command === null) {
                    $problems[] = "{$path}: `fsa {$call['command']}` is not a command ({$call['text']})";

                    continue;
                }
                // The image's entry point turns --version into --release for install and adopt (docker/app/fsa).
                $aliases = in_array($call['command'], ['install', 'adopt'], true) ? ['version', 'help'] : ['help'];
                foreach (array_diff($call['flags'], $aliases) as $flag) {
                    if (! $command->getDefinition()->hasOption($flag)) {
                        $problems[] = "{$path}: `fsa {$call['command']}` has no --{$flag} ({$call['text']})";
                    }
                }
            }
        }
    }

    expect($problems)->toBe([]);
});

it('names only install.sh flags that install.sh or fsa install take', function (): void {
    $install = Artisan::all()['fsa:install']->getDefinition();
    $script = (string) file_get_contents(base_path('distribution/install.sh'));
    preg_match_all('/^\t\t(-[^)]*)\)/m', $script, $cases);
    $own = array_map(static fn (string $flag): string => ltrim($flag, '-'), preg_split('/[\s|]+/', implode(' | ', $cases[1])) ?: []);
    $own = array_map(static fn (string $flag): string => preg_replace('/=\*$/', '', $flag) ?? $flag, $own);
    $problems = [];
    foreach (guideFiles() as $path => $markdown) {
        foreach (GuideReferences::code($markdown) as $code) {
            foreach (GuideReferences::installCalls($code) as $call) {
                foreach ($call['flags'] as $flag) {
                    if (! in_array($flag, $own, true) && ! $install->hasOption($flag)) {
                        $problems[] = "{$path}: install.sh takes no --{$flag} ({$call['text']})";
                    }
                }
            }
        }
    }

    expect($own)->toContain('dir', 'version', 'link', 'no-link', 'adopt', 'help')
        ->and($problems)->toBe([]);
});

it('names only artisan commands that exist', function (): void {
    $commands = array_keys(Artisan::all());
    $problems = [];
    foreach (guideFiles() as $path => $markdown) {
        foreach (GuideReferences::code($markdown) as $code) {
            foreach (array_diff(GuideReferences::artisanCommands($code), $commands) as $name) {
                $problems[] = "{$path}: `php artisan {$name}` is not a command";
            }
        }
    }

    expect($problems)->toBe([]);
});

it('names only environment variables the code reads', function (): void {
    $corpus = guideCodeCorpus();
    $problems = [];
    foreach (guideFiles() as $path => $markdown) {
        $names = array_unique(array_merge(...array_map(GuideReferences::environmentVariables(...), GuideReferences::code($markdown))));
        foreach ($names as $name) {
            // api.md's signature check runs on the integrator's server: its secret variable and PHP's HTTP_* request
            // headers are not variables of this application.
            if ($path === 'docs/guide/api.md' && ($name === 'FORM_SCHEMA_WEBHOOK_SECRET' || str_starts_with($name, 'HTTP_'))) {
                continue;
            }
            if (preg_match('/(?<![A-Za-z0-9_])'.preg_quote($name, '/').'(?![A-Za-z0-9_])/', $corpus) !== 1) {
                $problems[] = "{$path}: {$name}";
            }
        }
    }

    expect($problems)->toBe([]);
});

it('links only to files that exist', function (): void {
    $problems = [];
    foreach (guideFiles() as $path => $markdown) {
        foreach (GuideReferences::relativeLinks($markdown) as $target) {
            if (! file_exists(base_path(dirname($path).'/'.$target))) {
                $problems[] = "{$path}: {$target}";
            }
        }
    }

    expect($problems)->toBe([]);
});

it('finds fsa calls, install.sh flags and environment variables in code', function (): void {
    $code = "~/forms/fsa config set mail smtp --smtp-host x --password-file f --json | jq .\ncurl -fsSL https://x/install.sh | sh -s -- --dir ~/forms --demo";

    expect(GuideReferences::fsaCalls($code))->toBe([['command' => 'config', 'flags' => ['smtp-host', 'password-file', 'json'], 'text' => 'fsa config set mail smtp --smtp-host x --password-file f --json']])
        ->and(GuideReferences::installCalls($code)[0]['flags'])->toBe(['dir', 'demo'])
        ->and(GuideReferences::environmentVariables('FSA_RELEASE_URL=x APP_KEY a_b path/X_Y'))->toBe(['FSA_RELEASE_URL', 'APP_KEY'])
        ->and(GuideReferences::code("a `fsa status` b\n```sh\nfsa logs\n```\n"))->toBe(['fsa logs', 'fsa status'])
        ->and(GuideReferences::relativeLinks('[a](install.md#x) [b](https://x.test) [c](../adr/)'))->toBe(['install.md', '../adr/']);
});
