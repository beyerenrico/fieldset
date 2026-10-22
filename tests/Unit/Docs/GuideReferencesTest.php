<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Tests\Support\Docs\GuideReferences;

/*
 * ADR 0042's rule, checked (plan U21): every `fieldset` command and flag, install.sh flag, `php artisan` command,
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
            ->name(['*.php', '*.sh', '*.json', '*.yaml', '*.yml', '*.conf', 'fieldset', 'Dockerfile', 'Caddyfile*'])->notPath('cache');
        $corpus = implode("\n", array_map(static fn (SplFileInfo $file): string => $file->getContents(), iterator_to_array($finder, false)));
        foreach (['.env.example', '.env.production.example', 'compose.yaml', 'phpunit.xml'] as $file) {
            $corpus .= "\n".@file_get_contents(base_path($file));
        }
    }

    return $corpus;
}

it('names only fieldset commands and flags that exist', function (): void {
    $commands = Artisan::all();
    $problems = [];
    foreach (guideFiles() as $path => $markdown) {
        foreach (GuideReferences::code($markdown) as $code) {
            foreach (GuideReferences::fieldsetCalls($code) as $call) {
                $command = $commands['fieldset:'.$call['command']] ?? null;
                if ($command === null) {
                    $problems[] = "{$path}: `fieldset {$call['command']}` is not a command ({$call['text']})";

                    continue;
                }
                // The image's entry point turns --version into --release for install and adopt (docker/app/fieldset).
                $aliases = in_array($call['command'], ['install', 'adopt'], true) ? ['version', 'help'] : ['help'];
                foreach (array_diff($call['flags'], $aliases) as $flag) {
                    if (! $command->getDefinition()->hasOption($flag)) {
                        $problems[] = "{$path}: `fieldset {$call['command']}` has no --{$flag} ({$call['text']})";
                    }
                }
            }
        }
    }

    expect($problems)->toBe([]);
});

it('names only install.sh flags that install.sh or fieldset install take', function (): void {
    $install = Artisan::all()['fieldset:install']->getDefinition();
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
            if ($path === 'docs/guide/api.md' && ($name === 'FIELDSET_WEBHOOK_SECRET' || str_starts_with($name, 'HTTP_'))) {
                continue;
            }
            if (preg_match('/(?<![A-Za-z0-9_])'.preg_quote($name, '/').'(?![A-Za-z0-9_])/', $corpus) !== 1) {
                $problems[] = "{$path}: {$name}";
            }
        }
    }

    expect($problems)->toBe([]);
});

it('names no command of the former CLI fsa (renamed to fieldset in 2.1.0)', function (): void {
    $problems = [];
    foreach (guideFiles() as $path => $markdown) {
        foreach (GuideReferences::code($markdown) as $code) {
            foreach (GuideReferences::formerCliCalls($code) as $call) {
                $problems[] = "{$path}: `{$call}` names the former CLI; the command is now `fieldset`";
            }
        }
    }

    expect($problems)->toBe([]);
});

/**
 * Raw HTML tags and `<placeholder>` words outside code, as "file:line: …" problems (docs site plan U8).
 *
 * @param  array<string, string>  $files  path => contents
 * @return list<string>
 */
function guideRawTagProblems(array $files): array
{
    $problems = [];
    foreach ($files as $path => $markdown) {
        foreach (GuideReferences::rawTags($markdown) as $tag) {
            $problems[] = "{$path}:{$tag['line']}: {$tag['text']} is raw HTML outside code; GitHub drops it, put it in a code span";
        }
    }

    return $problems;
}

it('shows no raw HTML tag or <placeholder> outside code spans and fenced blocks', function (): void {
    expect(guideRawTagProblems(guideFiles()))->toBe([]);
});

it('names the file and line of a raw <placeholder> outside code', function (): void {
    $guide = "# Sign in\n\nChoose \"Weiter mit <organization>\" (\"Continue with\n<organization>\").\n";

    expect(guideRawTagProblems(['docs/guide/field-app-guide.md' => $guide]))->toBe([
        'docs/guide/field-app-guide.md:3: <organization> is raw HTML outside code; GitHub drops it, put it in a code span',
        'docs/guide/field-app-guide.md:4: <organization> is raw HTML outside code; GitHub drops it, put it in a code span',
    ]);
});

it('finds raw tags, but not in code spans, fenced blocks or autolinks', function (): void {
    $guide = implode("\n", [
        'Choose "Weiter mit `<organization>`" or ``a `<b>` c``.',
        '```sh',
        'curl https://x/install.sh | sh -s -- --dir <dir>',
        '```',
        '   ~~~',
        '   <div>',
        '   ~~~',
        'See <https://beyerenrico.github.io/fieldset/>, <mailto:a@example.com> and <a@example.com>; 1 < 2, a <= b.',
        'A `span over',
        'two <lines>` and <br>, </p>, <img src="x" />, <release tag> here.',
    ]);

    expect(GuideReferences::rawTags($guide))->toBe([
        ['line' => 10, 'text' => '<br>'],
        ['line' => 10, 'text' => '</p>'],
        ['line' => 10, 'text' => '<img src="x" />'],
        ['line' => 10, 'text' => '<release tag>'],
    ]);
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

it('finds fieldset calls, install.sh flags and environment variables in code', function (): void {
    $code = "~/forms/fieldset config set mail smtp --smtp-host x --password-file f --json | jq .\ncurl -fsSL https://x/install.sh | sh -s -- --dir ~/forms --demo";

    expect(GuideReferences::fieldsetCalls($code))->toBe([['command' => 'config', 'flags' => ['smtp-host', 'password-file', 'json'], 'text' => 'fieldset config set mail smtp --smtp-host x --password-file f --json']])
        ->and(GuideReferences::installCalls($code)[0]['flags'])->toBe(['dir', 'demo'])
        ->and(GuideReferences::environmentVariables('FIELDSET_RELEASE_URL=x APP_KEY a_b path/X_Y'))->toBe(['FIELDSET_RELEASE_URL', 'APP_KEY'])
        ->and(GuideReferences::code("a `fieldset status` b\n```sh\nfieldset logs\n```\n"))->toBe(['fieldset logs', 'fieldset status'])
        ->and(GuideReferences::relativeLinks('[a](install.md#x) [b](https://x.test) [c](../adr/)'))->toBe(['install.md', '../adr/']);
});

it('finds invocations of the former CLI fsa, but not the bare name or other words starting with it', function (): void {
    $guide = "Run `fsa install --dir ~/forms`, then:\n```sh\n~/forms/fsa status --json | jq .\nphp artisan fsa:backup\n```\n`fsa` sets it; `fsa-test-x`, `fsa_x`, `fsautil x`.\n";
    $calls = array_merge(...array_map(GuideReferences::formerCliCalls(...), GuideReferences::code($guide)));

    expect($calls)->toBe(['fsa status', 'fsa:backup', 'fsa install'])
        ->and(GuideReferences::formerCliCalls('fieldset install --dir x'))->toBe([]);
});
