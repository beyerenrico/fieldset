<?php

declare(strict_types=1);

use App\Domain\Instance\Backup\ProducedFile;
use App\Domain\Instance\Backup\ToolProcess;
use Tests\Support\Instance\TemporaryInstance;

/*
 * The runner of the backup programs (M12 gate S1, ADR 0084): exit code, stdout, the tail of stderr, one deadline, and a
 * file the caller created as the program's stdout, read back through the caller's own descriptor.
 */

afterEach(function (): void {
    TemporaryInstance::cleanup();
});

it('reports the exit code, stdout and the tail of stderr', function (): void {
    $result = ToolProcess::run(['sh', '-c', 'echo out; echo err >&2; exit 3'], [], 10.0);

    expect([$result->exitCode, $result->stdout, trim($result->stderr)])->toBe([3, "out\n", 'err']);
});

it('streams stdout in chunks when asked and passes the environment on top of its own', function (): void {
    $chunks = '';
    $types = [];
    $result = ToolProcess::run(['sh', '-c', 'printf "%s" "$FSA_TOOL_TEST"; seq 1 20000'], ['FSA_TOOL_TEST' => 'value:'], 10.0, static function (string $type, string $chunk) use (&$chunks, &$types): void {
        $chunks .= $chunk;
        $types[$type] = true;
    });

    expect($result->exitCode)->toBe(0)->and($result->stdout)->toBe('')->and(array_keys($types))->toBe(['out'])
        ->and($chunks)->toStartWith("value:1\n2\n")->toEndWith("20000\n");
});

it('kills a program that outlives its deadline', function (): void {
    $started = microtime(true);
    $result = ToolProcess::run(['sleep', '20'], [], 0.5);

    expect($result->exitCode)->toBe(ToolProcess::TIMED_OUT)->and($result->stderr)->toContain('sleep timed out')
        ->and(microtime(true) - $started)->toBeLessThan(5.0);
});

it('writes stdout into a file the caller created, and the caller reads back exactly those bytes', function (): void {
    $directory = TemporaryInstance::directory();
    $path = $directory->root.'/out.bin';
    $handle = ProducedFile::create($path);
    $result = ToolProcess::run(['sh', '-c', 'head -c 300000 /dev/zero | tr "\\0" "a"; echo done >&2'], [], 10.0, null, $handle);
    $produced = ProducedFile::of($handle);
    fclose($handle);

    expect($result->exitCode)->toBe(0)->and($result->stdout)->toBe('')
        ->and(substr(sprintf('%o', fileperms($path)), -3))->toBe('600')
        ->and([$produced->bytes, $produced->sha256])->toBe([300000, hash('sha256', str_repeat('a', 300000))])
        ->and($produced->inode)->toBe(fileinode($path))
        ->and(fn () => ProducedFile::create($path))->toThrow(RuntimeException::class, 'Cannot create out.bin');
});

it('reports a program that cannot start', function (): void {
    $result = ToolProcess::run(['/nonexistent/fsa-tool'], [], 5.0);

    expect($result->successful())->toBeFalse();
});
