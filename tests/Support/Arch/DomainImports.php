<?php

declare(strict_types=1);

namespace Tests\Support\Arch;

use FilesystemIterator;
use PhpToken;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * What the domain modules import: for each module under app/Domain, the other modules and the two outer layers
 * (App\Http, App\Filament) it names, found by tokenizing every file (imports and fully qualified references alike).
 * Pest `arch()` cannot do this: it cannot fail on a baseline entry that no import backs any more (LEARNINGS 78).
 */
final readonly class DomainImports
{
    public const array LAYERS = ['App\Http', 'App\Filament'];

    public function __construct(private string $appPath) {}

    /** @return list<string> */
    public function modules(): array
    {
        $dirs = glob($this->appPath.'/Domain/*', GLOB_ONLYDIR) ?: [];
        $names = array_map(basename(...), $dirs);
        sort($names);

        return $names;
    }

    /**
     * @return array<string, array{modules: list<string>, files: array<string, list<string>>}>
     *                                                                                         `files` maps a layer to the files (relative to app/Domain) that name it
     */
    public function scan(): array
    {
        $result = [];
        foreach ($this->modules() as $module) {
            $modules = [];
            $files = [];
            foreach ($this->phpFiles($this->appPath.'/Domain/'.$module) as $file) {
                $relative = $module.substr($file, strlen($this->appPath.'/Domain/'.$module));
                foreach ($this->references($file) as $name) {
                    $target = $this->target($name, $module);
                    if ($target !== null && in_array($target, self::LAYERS, true)) {
                        $files[$target][] = $relative;
                    } elseif ($target !== null) {
                        $modules[] = $target;
                    }
                }
            }
            $modules = array_values(array_unique($modules));
            sort($modules);
            $result[$module] = ['modules' => $modules, 'files' => $this->sorted($files)];
        }

        return $result;
    }

    /**
     * Pairs of [file, import] for the finding messages.
     *
     * @return array<string, list<string>> import name by relative file
     */
    public function importsByFile(string $module): array
    {
        $found = [];
        foreach ($this->phpFiles($this->appPath.'/Domain/'.$module) as $file) {
            $found[$module.substr($file, strlen($this->appPath.'/Domain/'.$module))] = $this->references($file);
        }

        return $found;
    }

    /** @return string|null a module name, a layer, or null for the own module and everything else */
    public function target(string $name, string $ownModule): ?string
    {
        foreach (self::LAYERS as $layer) {
            if ($name === $layer || str_starts_with($name, $layer.'\\')) {
                return $layer;
            }
        }
        if (preg_match('/^App\\\\Domain\\\\([^\\\\]+)/', $name, $m) === 1 && $m[1] !== $ownModule) {
            return $m[1];
        }

        return null;
    }

    /** @return list<string> fully qualified names (without the leading backslash) under App\Domain, App\Http, App\Filament */
    public function references(string $file): array
    {
        $names = [];
        $afterNamespace = false;
        foreach (PhpToken::tokenize((string) file_get_contents($file)) as $token) {
            if ($token->is(T_NAMESPACE)) {
                $afterNamespace = true;

                continue;
            }
            if ($token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                continue;
            }
            if ($token->is([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]) && ! $afterNamespace) {
                $name = ltrim(rtrim($token->text, '\\'), '\\');
                if (str_starts_with($name, 'App\\')) {
                    $names[] = $name;
                }
            }
            $afterNamespace = false;
        }

        return $names;
    }

    /** @return list<string> */
    private function phpFiles(string $directory): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /**
     * @param  array<string, list<string>>  $files
     * @return array<string, list<string>>
     */
    private function sorted(array $files): array
    {
        ksort($files);

        return array_map(static function (array $list): array {
            $list = array_values(array_unique($list));
            sort($list);

            return $list;
        }, $files);
    }
}
