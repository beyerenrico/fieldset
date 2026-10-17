<?php

declare(strict_types=1);

/*
 * Performance fixtures (M8-T05), run from the host against the dev database after
 * `php artisan app:seed-load demo 100000`:
 *
 *     php scripts/perf/fixtures.php [organization-slug] [audit-rows]
 *
 * Adds what the load generator does not create and prints the ids the measurement scripts need
 * (storage/agent-logs/perf/fixtures.json):
 * - audit entries (default 150,000) for the organization, sealed with synthetic chain values, spread
 *   over the last year; mostly submission events of generated submissions, so record histories fill;
 * - two saved reports (sum of net_total by service type over everything; by month for the last year);
 * - a published 300-element form ("Perf 300", granted to field workers) for the builder and Field App;
 * - an integration API token of the owner with every ability (plain text in the JSON, dev only);
 * - sample ids: a completed submission with task, workflow run, rendition and mail; a number to search.
 *
 * DEVELOPMENT ONLY. Audit entries are append-only (trigger, ADR 0038): remove them with
 * `php artisan migrate:fresh --seed --force`, not with `app:seed-load --purge`.
 */

use App\Domain\Dashboards\Performance\LoadGenerator;
use App\Domain\Forms\Actions\CreateForm;
use App\Domain\Forms\Actions\PublishForm;
use App\Domain\Forms\Actions\SyncFormAccess;
use App\Domain\Forms\Models\Form;
use App\Domain\Forms\Support\InvalidDefinitionException;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Identity\Tokens\Actions\CreateApiToken;
use App\Domain\Identity\Tokens\TokenAbility;
use App\Domain\Reports\Models\SavedReport;
use App\Domain\Reports\Saved\SaveReport;
use FormSchema\Json\Json;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (app()->isProduction()) {
    fwrite(STDERR, "Refusing to run in production.\n");
    exit(1);
}

$slug = $argv[1] ?? 'demo';
$auditRows = (int) ($argv[2] ?? 150_000);
$org = Organization::query()->where('slug', $slug)->firstOrFail();
$owner = User::query()->where('email', 'owner@demo.test')->firstOrFail();
$tenant = app(TenantContext::class);

/** Audit entries in one INSERT … SELECT: submission events (85 %), form events (10 %), member events (5 %). */
function seedAudit(Organization $org, int $rows): int
{
    $have = DB::table('activity_log')->where('organization_id', $org->id)->count();
    if ($have >= $rows) {
        return 0;
    }
    $needed = $rows - $have;
    $userIds = DB::table('organization_user')->where('organization_id', $org->id)->pluck('user_id')->all();
    $form = DB::table('forms')->where('organization_id', $org->id)->orderBy('id')->value('id');
    $membership = DB::table('organization_user')->where('organization_id', $org->id)->orderBy('id')->value('id');
    $startSeq = 10_000_000 + (int) DB::table('activity_log')->where('organization_id', $org->id)->max('chain_seq');

    DB::statement(<<<'SQL'
        WITH subs AS (
            SELECT uuid, submitted_at, row_number() OVER (ORDER BY uuid) AS rn
            FROM submissions WHERE organization_id = ? AND submitted_at IS NOT NULL
        ), total AS (SELECT count(*) AS c FROM subs)
        INSERT INTO activity_log (log_name, description, subject_type, subject_id, event, causer_type, causer_id,
            attribute_changes, properties, organization_id, actor_type, ip_address, user_agent, request_id,
            created_at, updated_at, chain_seq, prev_hash, hash)
        SELECT 'audit', e.event, e.subject_type, e.subject_id, e.event,
               CASE WHEN e.system THEN NULL ELSE 'App\Domain\Identity\Models\User' END,
               CASE WHEN e.system THEN NULL ELSE (?::bigint[])[1 + (g.n % array_length(?::bigint[], 1))] END,
               '[]', e.props, ?, CASE WHEN e.system THEN 'system' ELSE 'user' END,
               CASE WHEN e.system THEN NULL ELSE '10.0.' || (g.n % 200) || '.' || (g.n % 250) END,
               CASE WHEN e.system THEN NULL ELSE 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/141 Mobile' END,
               gen_random_uuid()::text, e.at, e.at, ? + g.n,
               md5(g.n::text) || md5('p' || g.n), md5('h' || g.n) || md5(g.n::text || 'h')
        FROM generate_series(1, ?) AS g(n)
        CROSS JOIN total
        JOIN subs s ON s.rn = 1 + (g.n * 7919) % total.c
        CROSS JOIN LATERAL (
            SELECT (ARRAY['submission.created', 'submission.submitted', 'submission.review_requested', 'submission.approved',
                          'submission.pdf_generated', 'submission.completed', 'document.sent'])[1 + g.n % 7] AS event,
                   'App\Domain\Submissions\Models\Submission' AS subject_type, s.uuid::text AS subject_id,
                   (g.n % 7) IN (2, 4, 5, 6) AS system,
                   ('{"number":"LT-' || g.n || '"}')::json AS props,
                   s.submitted_at + make_interval(mins => g.n % 600) AS at
        ) e
        SQL, [$org->id, '{'.implode(',', $userIds).'}', '{'.implode(',', $userIds).'}', $org->id, $startSeq, (int) ceil($needed * 0.85)]);

    DB::statement(<<<'SQL'
        INSERT INTO activity_log (log_name, description, subject_type, subject_id, event, causer_type, causer_id,
            attribute_changes, properties, organization_id, actor_type, ip_address, user_agent, request_id,
            created_at, updated_at, chain_seq, prev_hash, hash)
        SELECT 'audit', ev, st, sid, ev, 'App\Domain\Identity\Models\User', (?::bigint[])[1 + (g.n % array_length(?::bigint[], 1))],
               '{"old":{"name":"Alt"},"attributes":{"name":"Neu"}}', '[]', ?, 'user', '10.1.0.' || (g.n % 250),
               'Mozilla/5.0 (Macintosh; Intel Mac OS X 15_0) AppleWebKit/605.1.15 Safari/605.1.15', gen_random_uuid()::text,
               now() - make_interval(mins => (g.n * 37) % (365 * 24 * 60)), now() - make_interval(mins => (g.n * 37) % (365 * 24 * 60)),
               ? + g.n, md5(g.n::text), md5('h' || g.n) || md5(g.n::text)
        FROM generate_series(1, ?) AS g(n)
        CROSS JOIN LATERAL (
            SELECT CASE WHEN g.n % 3 = 0 THEN 'member.roles_changed' WHEN g.n % 3 = 1 THEN 'form.updated' ELSE 'form.published' END AS ev,
                   CASE WHEN g.n % 3 = 0 THEN 'App\Domain\Identity\Models\Membership' ELSE 'App\Domain\Forms\Models\Form' END AS st,
                   CASE WHEN g.n % 3 = 0 THEN ?::text ELSE ?::text END AS sid
        ) x
        SQL, ['{'.implode(',', $userIds).'}', '{'.implode(',', $userIds).'}', $org->id, $startSeq + 1_000_000, max(0, $needed - (int) ceil($needed * 0.85)), (string) $membership, (string) $form]);

    DB::statement('ANALYZE activity_log');

    return DB::table('activity_log')->where('organization_id', $org->id)->count() - $have;
}

/** A 300-element definition: 10 pages × 3 sections × 9 fields (+ the sections and 3 calculated per page). */
function perf300Definition(): stdClass
{
    $types = ['text', 'number', 'select', 'date', 'textarea', 'checkbox', 'money', 'email', 'radio'];
    $pages = [];
    $count = 0;
    for ($p = 1; $p <= 10; $p++) {
        $sections = [];
        for ($s = 1; $s <= 3; $s++) {
            $fields = [];
            for ($f = 1; $f <= 9; $f++) {
                $type = $types[($f - 1) % count($types)];
                $key = "p{$p}_s{$s}_{$type}_{$f}";
                $field = ['id' => (string) Str::uuid7(), 'type' => $type, 'key' => $key, 'label' => "Feld {$p}.{$s}.{$f} ({$type})"];
                if (in_array($type, ['select', 'radio'], true)) {
                    $field['options'] = [['value' => 'a', 'label' => 'Option A'], ['value' => 'b', 'label' => 'Option B'], ['value' => 'c', 'label' => 'Option C']];
                }
                if ($type === 'money') {
                    $field['currency'] = 'EUR';
                }
                if ($type === 'date' && $f === 4) {
                    $field['visibleIf'] = ['==' => [['var' => "p{$p}_s{$s}_select_3"], 'b']];
                }
                $fields[] = $field;
                $count++;
            }
            $sections[] = ['id' => (string) Str::uuid7(), 'type' => 'section', 'key' => "p{$p}_s{$s}", 'label' => "Abschnitt {$p}.{$s}", 'elements' => $fields];
            $count++;
        }
        $sections[] = ['id' => (string) Str::uuid7(), 'type' => 'calculated', 'key' => "p{$p}_total", 'label' => "Summe Seite {$p}", 'format' => 'number',
            'expression' => ['dec.add' => [['var' => "p{$p}_s1_number_2"], ['var' => "p{$p}_s2_number_2"], ['var' => "p{$p}_s3_number_2"]]]];
        $count++;
        $pages[] = ['id' => (string) Str::uuid7(), 'key' => "page_{$p}", 'title' => "Seite {$p}", 'elements' => $sections];
    }

    return Json::decode((string) json_encode([
        'specVersion' => '1.0.0', 'key' => 'perf_300', 'title' => 'Perf 300', 'locale' => 'de-DE',
        'settings' => ['layout' => 'wizard'], 'pages' => $pages,
    ]));
}

$audit = seedAudit($org, $auditRows);

$result = $tenant->run($org, function () use ($org, $owner): array {
    $service = Form::query()->where('key', 'service_report')->firstOrFail();

    $reports = [];
    foreach ([
        ['name' => 'Perf: Netto nach Art (alle)', 'group_by' => 'field:service_type', 'filters' => []],
        ['name' => 'Perf: Netto pro Monat (letztes Jahr)', 'group_by' => 'month', 'filters' => ['period' => 'last_year']],
    ] as $spec) {
        $existing = SavedReport::query()->where('name', $spec['name'])->first();
        $reports[] = ($existing ?? app(SaveReport::class)->handle($owner, null, $spec + [
            'form_id' => $service->id, 'metric' => 'sum', 'metric_key' => 'net_total', 'visibility' => 'organization',
        ]))->id;
    }

    $perf = Form::query()->where('key', 'perf_300')->first();
    if (! $perf instanceof Form) {
        [$perf] = app(CreateForm::class)->handle($owner, 'Perf 300', perf300Definition());
        try {
            app(PublishForm::class)->handle($perf, 'Perf 300', $owner);
        } catch (InvalidDefinitionException $e) {
            fwrite(STDERR, json_encode($e->report->toArray(), JSON_PRETTY_PRINT).PHP_EOL);
            throw $e;
        }
        app(SyncFormAccess::class)->handle($perf->refresh(), [], ['field_worker'], $owner);
    }

    $token = app(CreateApiToken::class)->handle($owner, $org, 'perf '.now()->format('His'), TokenAbility::values(), now()->addDay())->plainTextToken;

    $sample = DB::table('submissions as s')
        ->where('s.organization_id', $org->id)->where('s.status', 'completed')->where('s.device->generator', LoadGenerator::MARKER)
        ->whereExists(fn ($q) => $q->from('tasks')->whereColumn('tasks.submission_uuid', 's.uuid'))
        ->whereExists(fn ($q) => $q->from('workflow_runs')->whereColumn('workflow_runs.submission_uuid', 's.uuid'))
        ->whereExists(fn ($q) => $q->from('mail_messages')->whereColumn('mail_messages.submission_uuid', 's.uuid'))
        ->orderByDesc('s.submitted_at')->first(['s.uuid', 's.number']);
    $inReview = DB::table('submissions')->where('organization_id', $org->id)->where('status', 'in_review')->orderByDesc('submitted_at')->value('uuid');

    return [
        'organization' => ['id' => $org->id, 'slug' => $org->slug],
        'serviceReport' => ['id' => $service->id, 'versionId' => $service->current_version_id],
        'perf300' => ['id' => $perf->id, 'versionId' => $perf->refresh()->current_version_id],
        'savedReports' => $reports,
        'token' => $token,
        'sample' => ['completed' => $sample?->uuid, 'number' => $sample?->number, 'inReview' => $inReview],
        'counts' => [
            'submissions' => DB::table('submissions')->where('organization_id', $org->id)->count(),
            'submission_values' => DB::table('submission_values')->where('organization_id', $org->id)->count(),
            'tasks' => DB::table('tasks')->where('organization_id', $org->id)->count(),
            'renditions' => DB::table('renditions')->where('organization_id', $org->id)->count(),
            'workflow_runs' => DB::table('workflow_runs')->where('organization_id', $org->id)->count(),
            'mail_messages' => DB::table('mail_messages')->where('organization_id', $org->id)->count(),
            'activity_log' => DB::table('activity_log')->where('organization_id', $org->id)->count(),
            'activity_log_all' => DB::table('activity_log')->count(),
        ],
    ];
});
$result['auditInserted'] = $audit;

$dir = __DIR__.'/../../storage/agent-logs/perf';
@mkdir($dir, 0775, true);
file_put_contents($dir.'/fixtures.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
