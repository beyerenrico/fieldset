<?php

declare(strict_types=1);

namespace App\Domain\Instance\Console;

use App\Console\Support\JsonResult;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\Upgrade\ChildMigration;
use App\Domain\Instance\Upgrade\StageRenderer;
use App\Domain\Instance\Upgrade\UpgradeJournal;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Hidden;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * `fsa upgrade-child` (an image role, hidden from users; KTD19 step 6, R36, plan U18, ADR 0086): what the **target**
 * image does for an upgrade, in a container the installed supervisor starts without the Docker socket, on the instance
 * network, with the instance directory read-only and only its stage directory (FSA_UPGRADE_STAGE) writable: render the
 * target's compose and env files into the stage (StageRenderer), then migrate with them (ChildMigration). The outcome
 * goes to `result.json` in the stage, where the supervisor reads it after the container exited (a crash leaves none),
 * and to stdout.
 */
#[Description('Render and migrate the target release of an upgrade (an fsa upgrade role; use fsa upgrade)')]
#[Signature('fsa:upgrade-child
        {--dir= : The instance directory (mounted read-only)}
        {--json : Print one JSON document on stdout}')]
#[Hidden]
final class UpgradeChildCommand extends Command
{
    private const string COMMAND = 'upgrade-child';

    public function handle(): int
    {
        umask(0077);
        $stage = (string) getenv(StageRenderer::STAGE_ENV);
        $data = ['staged' => []];

        try {
            $directory = InstanceDirectory::open(rtrim((string) $this->option('dir'), '/'));
            $this->laravel->instance(InstanceDirectory::class, $directory);
            $data['staged'] = $this->laravel->make(StageRenderer::class)->render($stage);
            $request = json_decode((string) file_get_contents($stage.'/'.StageRenderer::REQUEST), true);
            $from = is_array($request) && is_string($request['from'] ?? null) ? $request['from'] : null;
            $id = substr((string) preg_replace('/^.*upgrade-/', '', $stage), 0, 12);
            $migration = $this->laravel->make(ChildMigration::class)->run($stage, $id, $from);
        } catch (InstanceException $e) {
            return $this->finish($stage, JsonResult::failed(self::COMMAND, $e->errorCode, $e->getMessage(), $e->hint), $data);
        } catch (Throwable $e) {
            return $this->finish($stage, JsonResult::failed(self::COMMAND, 'child_failed', $e->getMessage()), $data);
        }
        if (! $migration->ok) {
            return $this->finish($stage, JsonResult::failed(self::COMMAND, $migration->errorCode(), $migration->errorMessage(), $migration->errorHint()), [...$data, 'output' => $migration->output]);
        }

        return $this->finish($stage, JsonResult::ok(self::COMMAND, [
            ...$data, 'migrated' => UpgradeJournal::list($migration->data['migrated'] ?? null), 'steps' => UpgradeJournal::list($migration->data['steps'] ?? null),
            'invalid_indexes' => UpgradeJournal::list($migration->data['invalid_indexes'] ?? null),
        ], changed: true), []);
    }

    /** @param  array<string, mixed>  $extra  merged into the result file (what was staged before a failure) */
    private function finish(string $stage, JsonResult $result, array $extra): int
    {
        $document = $result->toArray();
        $file = ['status' => $document['status'], ...$document['data'], ...$extra, ...($document['errors'] === [] ? [] : ['error' => $document['errors'][0]])];
        if ($stage !== '' && is_dir($stage) && ! is_link($stage)) {
            @file_put_contents($stage.'/'.StageRenderer::RESULT, json_encode($file, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
        }

        return $result->emit($this);
    }
}
