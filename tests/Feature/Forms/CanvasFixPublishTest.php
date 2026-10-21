<?php

declare(strict_types=1);

use App\Domain\Forms\Actions\CreateFormFromTemplate;
use App\Domain\Forms\Actions\PublishForm;
use App\Domain\Forms\Actions\RollbackForm;
use App\Domain\Forms\Actions\SaveDraft;
use App\Domain\Forms\Models\Form;
use App\Domain\Forms\Models\FormVersion;
use App\Domain\Forms\Support\DraftChangedException;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Filament\Admin\Resources\Forms\Pages\CanvasBuilder;
use Fieldset\Json\Json;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

/* Gate fix (M7): publishing never reverts a save that landed after the caller checked the draft. */

beforeEach(function (): void {
    $this->org = makeOrganization();
    $this->designer = memberOf($this->org, SystemRole::Designer);
    app(TenantContext::class)->set($this->org);
    $this->form = app(CreateFormFromTemplate::class)->handle($this->designer, 'service_report');
});

/** Loads the form, then saves another title in the database behind the loaded model's back. */
function staleFormAfterConcurrentSave(Form $form, mixed $actor): array
{
    $stale = Form::query()->findOrFail($form->getKey());
    $checksum = Json::checksum($stale->draft_definition);
    $elsewhere = Json::decode(Json::encode($form->fresh()->draft_definition));
    $elsewhere->title = 'Gleichzeitig gespeichert';
    app(SaveDraft::class)->handle($form->fresh(), $elsewhere, $actor, autosave: true);

    return [$stale, $checksum];
}

it('refuses to publish when the draft changed after the expected checksum', function (): void {
    [$stale, $checksum] = staleFormAfterConcurrentSave($this->form, $this->designer);

    expect(fn () => app(PublishForm::class)->handle($stale, 'v1', $this->designer, expectedChecksum: $checksum))
        ->toThrow(DraftChangedException::class);

    expect(FormVersion::query()->where('form_id', $this->form->id)->exists())->toBeFalse()
        ->and($this->form->fresh()->draft_definition->title)->toBe('Gleichzeitig gespeichert')
        ->and($this->form->fresh()->current_version_id)->toBeNull();
});

it('publishes the stored draft, not the stale model, and never writes the draft back', function (): void {
    [$stale] = staleFormAfterConcurrentSave($this->form, $this->designer);
    $stale->draft_definition->title = 'Veraltet im Speicher'; // a mutated cast value must not leak into the row
    $stored = Json::checksum($this->form->fresh()->draft_definition);

    $version = app(PublishForm::class)->handle($stale, 'v1', $this->designer, expectedChecksum: $stored);

    expect($version->definition->title)->toBe('Gleichzeitig gespeichert')
        ->and($version->checksum)->toBe($stored)
        ->and($this->form->fresh()->draft_definition->title)->toBe('Gleichzeitig gespeichert')
        ->and(Json::checksum($this->form->fresh()->draft_definition))->toBe($stored)
        ->and($this->form->fresh()->current_version_id)->toBe($version->id);
});

it('still publishes without an expected checksum and still writes the draft on rollback', function (): void {
    $v1 = app(PublishForm::class)->handle($this->form, 'v1', $this->designer);
    $changed = Json::decode(Json::encode($this->form->fresh()->draft_definition));
    $changed->title = 'Zweite Fassung';
    app(SaveDraft::class)->handle($this->form->fresh(), $changed, $this->designer);
    app(PublishForm::class)->handle($this->form->fresh(), 'v2', $this->designer);

    $v3 = app(RollbackForm::class)->handle($this->form->fresh(), $v1, $this->designer);

    expect($v3->version)->toBe(3)
        ->and($this->form->fresh()->draft_definition->title)->toBe($v1->definition->title)
        ->and(Json::checksum($this->form->fresh()->draft_definition))->toBe($v1->checksum);
});

it('refuses a canvas publish when a save lands between the page guard and the publish', function (): void {
    actingInAdmin($this->designer, $this->org);
    $checksum = Json::checksum($this->form->draft_definition);
    $elsewhere = Json::decode(Json::encode($this->form->draft_definition));
    $elsewhere->title = 'Gleichzeitig gespeichert';
    $page = Livewire::test(CanvasBuilder::class, ['record' => $this->form->getKey()])
        ->mountAction(TestAction::make('publish')->arguments(['checksum' => $checksum]));
    $saved = false;
    // The guard has passed when PublishForm opens its transaction: another tab saves right then.
    Event::listen(TransactionBeginning::class, function () use (&$saved, $elsewhere): void {
        if (! $saved) {
            $saved = true;
            DB::table('forms')->where('id', $this->form->id)->update(['draft_definition' => Json::encode($elsewhere)]);
        }
    });

    $page->setActionData(['changelog' => 'Erste Version'])->callMountedAction()
        ->assertNotified(__('forms.canvas.stale_title'))
        ->assertNotDispatched(CanvasBuilder::PUBLISHED);

    // (The simulated save ran inside the refused transaction and rolled back with it; the unit tests
    // above show that a real concurrent save stays.)
    expect($saved)->toBeTrue()
        ->and(FormVersion::query()->where('form_id', $this->form->id)->exists())->toBeFalse()
        ->and($this->form->fresh()->current_version_id)->toBeNull();
});
