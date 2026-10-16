<?php

declare(strict_types=1);

use App\Domain\Forms\Models\Form;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Filament\Admin\Widgets\DraftFormsWidget;
use App\Filament\Admin\Widgets\KpiOverviewWidget;
use App\Filament\Admin\Widgets\RecentlyPublishedWidget;
use App\Filament\Admin\Widgets\RecentSubmissionsWidget;
use App\Filament\Admin\Widgets\ReviewQueueWidget;
use App\Filament\Admin\Widgets\SubmissionsChartWidget;
use App\Filament\Admin\Widgets\WorkflowFailuresWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Livewire\Livewire;
use Tests\Feature\Dashboards\DashboardFixtures as D;

/*
 * §8.1 AC: every widget is scoped to the current organization. Two organizations hold different amounts
 * of every kind of dashboard data (A: 1 of each / 3 submissions, B: 2 of each / 5 submissions); each
 * widget, seen from either organization, reports only its own.
 */

/** @return array{org: Organization, owner: User, reviewer: User, viewer: User, designer: User, form: Form, numbers: list<string>, runs: list<int>} */
function scopedOrganization(string $prefix, int $units, int $submitted): array
{
    $org = makeOrganization(name: "Org {$prefix}");
    $owner = ownerOf($org);
    $reviewer = memberOf($org, SystemRole::Reviewer);
    $form = D::form($org, $owner);
    D::in($org, fn (): Form => Form::factory()->create(['name' => "{$prefix} draft form"]));

    $numbers = [];
    for ($i = 1; $i <= $submitted; $i++) {
        $numbers[] = "{$prefix}-{$i}";
        $submission = D::submission($form, $owner, 'submitted', now()->subHour(), "{$prefix}-{$i}");
        if ($i <= $units) {
            D::task($submission, ['assignee_user_id' => $reviewer->id]);
            D::sentMail($submission, now()->subHour());
            D::touchedAt(D::submission($form, $owner, 'draft'), now()->subDays(30));
        }
    }
    $runs = [];
    for ($i = 1; $i <= $units; $i++) {
        $runs[] = D::failedRun($org, now()->subHour())->id;
    }

    return ['org' => $org, 'owner' => $owner, 'reviewer' => $reviewer, 'viewer' => memberOf($org, SystemRole::Viewer),
        'designer' => memberOf($org, SystemRole::Designer), 'form' => $form, 'numbers' => $numbers, 'runs' => $runs, 'units' => $units];
}

beforeEach(function (): void {
    $this->orgs = ['A' => scopedOrganization('A', 1, 3), 'B' => scopedOrganization('B', 2, 5)];
});

dataset('perspectives', [['A', 'B'], ['B', 'A']]);

it('counts KPIs of the current organization only', function (string $mine): void {
    $o = $this->orgs[$mine];
    actingInAdmin($o['owner'], $o['org']);

    $stats = D::call(Livewire::test(KpiOverviewWidget::class), 'getStats');
    $values = array_map(fn (Stat $stat): string => (string) $stat->getValue(), $stats);

    expect($values)->toBe([(string) count($o['numbers']), (string) $o['units'], (string) $o['units'], (string) $o['units']]);
})->with('perspectives');

it('charts submissions of the current organization only', function (string $mine): void {
    $o = $this->orgs[$mine];
    actingInAdmin($o['owner'], $o['org']);

    $data = D::call(Livewire::test(SubmissionsChartWidget::class), 'getData');

    expect($data['datasets'])->toHaveCount(1)
        ->and($data['datasets'][0]['label'])->toBe($o['form']->name)
        ->and(array_sum($data['datasets'][0]['data']))->toBe(count($o['numbers']));
})->with('perspectives');

it('lists workflow failures of the current organization only', function (string $mine, string $other): void {
    $o = $this->orgs[$mine];
    actingInAdmin($o['owner'], $o['org']);

    $ids = Livewire::test(WorkflowFailuresWidget::class)->instance()->getTable()->getRecords()->pluck('id')->all();

    expect($ids)->toEqualCanonicalizing($o['runs'])->not->toContain(...$this->orgs[$other]['runs']);
})->with('perspectives');

it('shows the review queue of the current organization only', function (string $mine): void {
    $o = $this->orgs[$mine];
    actingInAdmin($o['reviewer'], $o['org']);

    $numbers = Livewire::test(ReviewQueueWidget::class)->instance()->getTable()->getRecords()->pluck('number')->all();

    expect($numbers)->toEqualCanonicalizing(array_slice($o['numbers'], 0, $o['units']));
})->with('perspectives');

it('lists recent submissions of the current organization only', function (string $mine): void {
    $o = $this->orgs[$mine];
    actingInAdmin($o['viewer'], $o['org']);

    $numbers = Livewire::test(RecentSubmissionsWidget::class)->instance()->getTable()->getRecords()->pluck('number')->all();

    expect($numbers)->toEqualCanonicalizing($o['numbers']);
})->with('perspectives');

it('lists draft forms and published versions of the current organization only', function (string $mine): void {
    $o = $this->orgs[$mine];
    actingInAdmin($o['designer'], $o['org']);

    $drafts = Livewire::test(DraftFormsWidget::class)->instance()->getTable()->getRecords()->pluck('name')->all();
    $published = Livewire::test(RecentlyPublishedWidget::class)->instance()->getTable()->getRecords()->pluck('form_id')->all();

    expect($drafts)->toBe(["{$mine} draft form"])
        ->and($published)->toBe([$o['form']->id]);
})->with('perspectives');
