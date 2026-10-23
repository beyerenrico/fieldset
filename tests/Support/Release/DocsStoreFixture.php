<?php

declare(strict_types=1);

namespace Tests\Support\Release;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * A miniature publish for docs/site/store/store-artifact.sh (DocsStoreScriptTest): a tag repository with the spec and
 * staged Markdown at v2.1.0-rc.1, a build artifact shaped like scripts/build.mjs writes it, and an empty docs-site
 * worktree. Plain git and files only; nothing is built.
 */
final class DocsStoreFixture
{
    public const string TAG = 'v2.1.0-rc.1';

    public const string TOOLING = 'abababababababababababababababababababab';

    public const string LATEST_INSTALL = 'https://github.com/beyerenrico/fieldset/releases/latest/download/install.sh';

    public const string CSP_META = '<meta http-equiv="Content-Security-Policy" content="default-src \'self\'; style-src \'self\' \'unsafe-inline\'; img-src \'self\' data:; connect-src \'none\'; form-action \'none\'">';

    public string $commit = '';

    public function __construct(public readonly string $dir)
    {
        File::deleteDirectory($dir);
        File::ensureDirectoryExists($dir);
        $this->commit = $this->tagRepository();
        $this->artifact();
        File::ensureDirectoryExists("{$dir}/site");
        $this->git('site', 'init', '-q');
        $this->git('site', 'checkout', '-q', '--orphan', 'docs-site');
    }

    /** Runs the store program with the fixture's arguments, any of them overridden. */
    public function store(array $overrides = []): Process
    {
        $arguments = [
            '--artifact' => "{$this->dir}/artifact", '--site' => "{$this->dir}/site", '--tag-repo' => "{$this->dir}/tag",
            '--tag' => self::TAG, '--commit' => $this->commit, '--minor' => '2.1', '--tooling' => self::TOOLING,
            ...$overrides,
        ];
        $command = ['bash', base_path('docs/site/store/store-artifact.sh')];
        foreach ($arguments as $flag => $value) {
            $command[] = $flag;
            $command[] = $value;
        }

        return tap(new Process($command, $this->dir, $this->gitEnvironment()))->run();
    }

    /** Commits a docs-site state by hand, as a later publish of another minor would have left it. */
    public function storeState(array $versions, array $files = []): void
    {
        $this->put('site/versions.json', json_encode($versions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        foreach ($files as $path => $contents) {
            $this->put("site/{$path}", $contents);
        }
        $this->git('site', 'add', '-A');
        $this->git('site', 'commit', '-q', '-m', 'fixture state');
    }

    /** The artifact as a non-root publish writes it: the copy and versions.json only. */
    public function withoutRoot(array $versions): void
    {
        foreach (['index.html', '404.html', 'assets', 'latest', 'spec'] as $entry) {
            File::delete("{$this->dir}/artifact/site/{$entry}");
            File::deleteDirectory("{$this->dir}/artifact/site/{$entry}");
        }
        $this->put('artifact/site/versions.json', json_encode($versions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    /** @return array<string, mixed> a versions.json copy entry */
    public static function copy(string $tag, string $builtWith = self::TOOLING): array
    {
        $version = substr($tag, 1);

        return [
            'minor' => implode('.', array_slice(explode('.', $version), 0, 2)), 'version' => $version, 'tag' => $tag,
            'prerelease' => str_contains($version, '-'), 'builtWith' => $builtWith,
        ];
    }

    public function put(string $path, string $contents): void
    {
        File::ensureDirectoryExists(dirname("{$this->dir}/{$path}"));
        File::put("{$this->dir}/{$path}", $contents);
    }

    public function edit(string $path, string $search, string $replace): void
    {
        $file = "{$this->dir}/{$path}";
        File::put($file, str_replace($search, $replace, (string) File::get($file)));
    }

    public function git(string $repository, string ...$arguments): string
    {
        $process = new Process(['git', '-C', "{$this->dir}/{$repository}", ...$arguments], null, $this->gitEnvironment());
        $process->mustRun();

        return trim($process->getOutput());
    }

    /** @return array<string, string> a git identity and no global or system configuration */
    private function gitEnvironment(): array
    {
        return [
            'GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_NOSYSTEM' => '1', 'GIT_AUTHOR_NAME' => 'fixture',
            'GIT_AUTHOR_EMAIL' => 'fixture@example.invalid', 'GIT_COMMITTER_NAME' => 'fixture',
            'GIT_COMMITTER_EMAIL' => 'fixture@example.invalid',
        ];
    }

    private function tagRepository(): string
    {
        $this->put('tag/spec/form-definition/1.0/meta-schema.json', "{\"\$id\": \"https://beyerenrico.github.io/fieldset/spec/form-definition/1.0/meta-schema.json\"}\n");
        $this->put('tag/spec/form-definition/1.0/roles.json', "{\"roles\": []}\n");
        $this->put('tag/spec/form-definition/1.0/README.md', "# Form definition 1.0\n");
        $this->put('tag/docs/guide/install.md', "# Install\n\n```sh\ncurl -fsSL ".self::LATEST_INSTALL." | sh\n```\n");
        $this->put('tag/docs/api/field-app-v1.md', "# Field App API\n");
        $this->put('tag/CHANGELOG.md', "# Changelog\n");
        $this->git('tag', 'init', '-q');
        $this->git('tag', 'add', '-A');
        $this->git('tag', 'commit', '-q', '-m', 'release');
        $this->git('tag', 'tag', '-a', self::TAG, '-m', self::TAG);

        return $this->git('tag', 'rev-parse', 'HEAD');
    }

    private function artifact(): void
    {
        $page = fn (string $base, string $body): string => "<!doctype html>\n<html lang=\"en\"><head><meta charset=\"utf-8\">"
            ."<link rel=\"preload stylesheet\" href=\"{$base}assets/style.css\" as=\"style\">"
            ."<script id=\"check-dark-mode\">document.documentElement.classList.add('dark')</script>"
            ."<script type=\"module\" src=\"{$base}assets/app.js\"></script></head><body>{$body}</body></html>\n";
        $download = 'https://github.com/beyerenrico/fieldset/releases/download/'.self::TAG.'/install.sh';
        $this->put('artifact/decision.json', "{\"action\": \"publish\"}\n");
        $this->put('artifact/site/2.1/index.html', $page('/fieldset/2.1/', "<p>Pre-release: curl -fsSL {$download} | sh</p>"));
        $this->put('artifact/site/2.1/install.html', $page('/fieldset/2.1/', '<code>curl -fsSL '.self::LATEST_INSTALL.' | sh</code>'));
        $this->put('artifact/site/2.1/assets/app.js', "export const app = 1;\n");
        $this->put('artifact/site/2.1/assets/style.css', "body { margin: 0 }\n");
        $this->put('artifact/site/2.1/api-reference.html', "<!doctype html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n"
            .self::CSP_META."\n<title>API reference</title>\n</head>\n<body>\n<script id=\"api-reference\" type=\"application/json\">{}</script>\n"
            ."<script src=\"scalar-api-reference.js\"></script>\n</body>\n</html>\n");
        $this->put('artifact/site/2.1/scalar-api-reference.js', "void 0;\n");
        $this->put('artifact/site/index.html', $page('/fieldset/', "<code>curl -fsSL {$download} | sh</code>"));
        $this->put('artifact/site/404.html', $page('/fieldset/', '<a href="/fieldset/latest/">latest</a>'));
        $this->put('artifact/site/assets/app.js', "export const root = 1;\n");
        $this->put('artifact/site/assets/style.css', "body { margin: 0 }\n");
        $this->put('artifact/site/latest/index.html', $page('/fieldset/', '<a href="/fieldset/2.1/">2.1</a>'));
        File::ensureDirectoryExists("{$this->dir}/artifact/site/spec/form-definition/1.0");
        foreach (['meta-schema.json', 'roles.json'] as $spec) {
            File::copy("{$this->dir}/tag/spec/form-definition/1.0/{$spec}", "{$this->dir}/artifact/site/spec/form-definition/1.0/{$spec}");
        }
        $this->put('artifact/site/spec/form-definition/1.0/index.html', $page('/fieldset/', '<h1>Form definition 1.0</h1>'));
        $this->put('artifact/site/versions.json', json_encode(
            ['schema' => 1, 'latest' => '2.1', 'copies' => [self::copy(self::TAG)]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        )."\n");
    }
}
