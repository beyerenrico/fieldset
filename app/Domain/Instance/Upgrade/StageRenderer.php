<?php

declare(strict_types=1);

namespace App\Domain\Instance\Upgrade;

use App\Domain\Instance\Compose\ComposeRenderer;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\State\EnvFiles;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceSecrets;
use App\Domain\Instance\State\InvalidState;
use App\Domain\Instance\State\UnsafePath;
use JsonException;

/**
 * The child's first half (KTD19 step 6, R36, plan U18, ADR 0086), run by the **target** image's fsa: it renders the
 * target release's compose file, env files and service files from the instance state (with the target's version and
 * digests) and the secrets of the current env files into the stage directory, with the target's own renderer. A target
 * that adds a service or an env key therefore starts from what it rendered itself, not from what the installed version
 * would render. The instance directory is mounted read-only; only the stage directory is writable.
 *
 * Stage layout (`operations/upgrade-<id>/`): `request.json` (written by the supervisor: version, digests, emulation),
 * `files/` (the rendered tree, an instance directory of its own), `result.json` (the child's outcome).
 */
final readonly class StageRenderer
{
    /** The child's environment variable naming its stage directory (never an argument). */
    public const string STAGE_ENV = 'FSA_UPGRADE_STAGE';

    public const string REQUEST = 'request.json';

    public const string RESULT = 'result.json';

    public const string FILES = 'files';

    public function __construct(private InstanceDirectory $directory, private ComposeRenderer $renderer) {}

    /**
     * @return list<string> the staged files, relative to `files/`
     *
     * @throws InstanceException exit 2 for an unusable stage or request
     */
    public function render(string $stage): array
    {
        $request = $this->request($stage);
        $secrets = InstanceSecrets::fromEnvFiles(new EnvFiles($this->directory))
            ?? throw new InvalidState('The app env file lacks APP_KEY or the database password; they are never generated again silently.');
        $state = $this->directory->state()->withRelease($request['version'], $request['digests']);
        $files = InstanceDirectory::initialize($stage.'/'.self::FILES);
        $this->renderer->write($files, $state, $secrets, $request['emulated']);

        return self::list($files->root);
    }

    /** Whether a stage path is one fsa makes: `<instance>/operations/upgrade-<hex>`. */
    public static function valid(InstanceDirectory $directory, string $stage): bool
    {
        return preg_match('#^'.preg_quote($directory->root.'/'.InstanceDirectory::OPERATIONS_DIR.'/upgrade-', '#').'[0-9a-f]{6,64}$#D', $stage) === 1 && is_dir($stage) && ! is_link($stage);
    }

    /** @return list<string> every regular file below `$root` (relative), the marker excluded */
    public static function list(string $root): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && ! $file->isLink()) {
                $relative = substr($file->getPathname(), strlen($root) + 1);
                if ($relative !== InstanceDirectory::MARKER) {
                    $files[] = $relative;
                }
            }
        }
        sort($files);

        return $files;
    }

    /** @return array{version: string, from: string|null, digests: array<string, string>, emulated: bool} */
    private function request(string $stage): array
    {
        if (! self::valid($this->directory, $stage)) {
            throw new UnsafePath($stage, 'it is not an upgrade stage of this instance');
        }
        try {
            $request = json_decode((string) @file_get_contents($stage.'/'.self::REQUEST), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $request = null;
        }
        $digests = is_array($request) ? UpgradeJournal::strings($request['digests'] ?? null) : [];
        if (! is_array($request) || ! is_string($request['version'] ?? null) || ! isset($digests['app'])) {
            throw new InstanceException('The upgrade stage holds no valid request.', ExitCode::InvalidInput, 'stage_invalid');
        }

        return ['version' => $request['version'], 'from' => is_string($request['from'] ?? null) ? $request['from'] : null, 'digests' => $digests, 'emulated' => ($request['emulated'] ?? false) === true];
    }
}
