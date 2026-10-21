<?php

declare(strict_types=1);

/*
 * Stands in for `fsa <command>` inside the real ops container during the bootstrap journey (plan U4, until U5's
 * commands exist): it reports what install.sh or the shim handed over (argv, user, groups, environment, mounts,
 * socket access) and runs the launch checks every fsa command runs, with the image's own classes. With
 * --secrets-file it writes that file like a command would. Exit codes follow LaunchGuard (3 nonce, 2 path).
 */

require '/var/www/html/vendor/autoload.php';

use App\Domain\Instance\Console\FilePathFlags;
use App\Domain\Instance\Console\LaunchGuard;
use App\Domain\Instance\HostFacts;
use App\Domain\Instance\InstanceException;

$arguments = array_slice($argv, 1);
$options = [];
foreach ($arguments as $index => $argument) {
    if (preg_match('/^--([a-z0-9-]+)(?:=(.*))?$/', $argument, $match) === 1) {
        $next = $arguments[$index + 1] ?? null;
        $options[$match[1]] = $match[2] ?? (FilePathFlags::classify($match[1]) !== null || $match[1] === 'dir' ? $next : true);
    }
}

$facts = HostFacts::fromEnvironment(getenv());
$directory = is_string($options['dir'] ?? null) ? $options['dir'] : (string) $facts->instanceDir;
$environment = array_filter(getenv(), static fn (string $key): bool => str_starts_with($key, 'FIELDSET_') || in_array($key, ['HOME', 'DOCKER_CONFIG', 'DOCKER_HOST'], true), ARRAY_FILTER_USE_KEY);
ksort($environment);
$report = [
    'argv' => $arguments,
    'uid' => posix_getuid(),
    'gid' => posix_getgid(),
    'groups' => posix_getgroups(),
    'cwd' => getcwd(),
    'env' => $environment,
    'docker_server' => trim((string) shell_exec('docker version --format "{{.Server.Version}}" 2>/dev/null')),
    'home_writable' => is_writable((string) getenv('HOME')),
    'instance' => [
        'visible' => is_dir($directory),
        'marker' => is_file($directory.'/.fsa-instance'),
        'shim' => is_file($directory.'/fsa'),
        'manifest' => is_file((string) getenv('FIELDSET_RELEASE_MANIFEST')),
        'writable' => is_writable($directory),
    ],
];

try {
    LaunchGuard::assertVisible($facts, $directory);
    LaunchGuard::assertFilePaths($facts, $directory, $options);
} catch (InstanceException $e) {
    echo json_encode(['probe' => $report, 'error' => ['code' => $e->errorCode, 'message' => $e->getMessage(), 'hint' => $e->hint]], JSON_UNESCAPED_SLASHES), "\n";
    exit($e->exitCode->value);
}

if (is_string($options['secrets-file'] ?? null)) {
    file_put_contents($options['secrets-file'], "{\"probe\":true}\n");
    chmod($options['secrets-file'], 0600);
}

echo json_encode(['probe' => $report], JSON_UNESCAPED_SLASHES), "\n";
