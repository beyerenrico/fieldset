<?php

declare(strict_types=1);

namespace App\Domain\Dashboards\Performance;

use App\Domain\Forms\Models\Form;
use App\Domain\Forms\Models\FormVersion;
use App\Domain\Identity\Models\Organization;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use stdClass;

/**
 * Bulk test data for measurements (M5-T05, `app:seed-load`): submissions spread over the last year
 * with a realistic status mix, their `submission_values`, review tasks, renditions (rows only, no files),
 * workflow runs and sent document mails. Plain chunked inserts — no model events, no jobs, no
 * notifications. Every generated submission carries `device.generator = app:seed-load` so purge()
 * removes exactly the generated rows.
 */
final readonly class LoadGenerator
{
    public const string MARKER = 'app:seed-load';

    public function __construct(private SubmissionDataFaker $faker, private LoadRows $rows) {}

    /** @param  (Closure(int $done): void)|null  $progress */
    public function generate(Organization $organization, Form $form, int $count, ?Closure $progress = null, int $chunk = 1000): LoadReport
    {
        $version = FormVersion::query()->withoutTenant()->whereKey($form->current_version_id)->first()
            ?? throw new InvalidArgumentException("Form {$form->id} has no published version.");
        if ($form->organization_id !== $organization->id) {
            throw new InvalidArgumentException("Form {$form->id} does not belong to organization {$organization->id}.");
        }

        $context = LoadContext::for($organization, $form, $version, Str::lower(Str::random(6)));
        $report = new LoadReport;
        $started = microtime(true);

        for ($done = 0; $done < $count; $done += $chunk) {
            $size = min($chunk, $count - $done);
            DB::transaction(function () use ($context, $version, $done, $size, $report): void {
                DB::statement('SET LOCAL synchronous_commit = off');
                $this->chunk($context, $version->definition, $done, $size, $report);
            });
            if ($progress instanceof Closure) {
                $progress($done + $size);
            }
        }
        $report->seconds = microtime(true) - $started;

        return $report;
    }

    /** Deletes the generated rows of the organization; returns the number of submissions removed. */
    public function purge(Organization $organization): int
    {
        return DB::transaction(function () use ($organization): int {
            DB::statement("SET LOCAL app.allow_immutable_delete = 'on'"); // renditions are immutable outside erasure
            $marked = fn (string $table, string $column = 'submission_uuid'): int => DB::delete(
                "DELETE FROM {$table} t USING submissions s WHERE t.{$column} = s.uuid AND s.organization_id = ? AND s.device->>'generator' = ?",
                [$organization->id, self::MARKER],
            );
            foreach (['submission_values', 'tasks', 'renditions', 'workflow_runs', 'mail_messages', 'submission_events'] as $table) {
                $marked($table);
            }

            return DB::delete("DELETE FROM submissions WHERE organization_id = ? AND device->>'generator' = ?", [$organization->id, self::MARKER]);
        });
    }

    private function chunk(LoadContext $context, stdClass $definition, int $offset, int $size, LoadReport $report): void
    {
        $submissions = [];
        $values = [];
        for ($i = 0; $i < $size; $i++) {
            $submission = $this->rows->submission($context, $offset + $i + 1);
            $at = $submission['submitted_at'] instanceof CarbonImmutable ? $submission['submitted_at'] : $submission['created_at'];
            $generated = $this->faker->make($definition, $at);
            $submission['data'] = json_encode($generated['data'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $submissions[] = $submission;
            if ($submission['status'] !== 'draft') {
                foreach ($generated['rows'] as $row) {
                    $values[] = $this->rows->value($context, $submission, $row);
                }
            }
        }

        $report->submissions += $this->insert('submissions', $submissions);
        $report->values += $this->insert('submission_values', $values);
        $report->tasks += $this->insert('tasks', $this->rows->tasks($context, $submissions));
        $report->renditions += $this->insert('renditions', $this->rows->renditions($context, $submissions));
        $report->runs += $this->insert('workflow_runs', $this->rows->runs($context, $submissions));
        $report->mails += $this->insert('mail_messages', $this->rows->mails($context, $submissions));
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function insert(string $table, array $rows): int
    {
        if ($rows === []) {
            return 0;
        }
        // Postgres allows 65535 bind parameters per statement.
        $perStatement = max(1, intdiv(60000, count($rows[0])));
        foreach (array_chunk($rows, $perStatement) as $part) {
            DB::table($table)->insert($part);
        }

        return count($rows);
    }
}
