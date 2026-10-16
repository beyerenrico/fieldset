<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Domain\Forms\Models\Form;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Reports\Enums\ExportFormat;
use App\Domain\Reports\Exports\StartExport;
use App\Domain\Reports\Filters\ReportFilters;
use App\Domain\Reports\ReportForms;
use App\Domain\Reports\Schema\FieldCatalog;
use App\Filament\Admin\Resources\Submissions\SubmissionResource;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

/**
 * Submission explorer per form (BUILD_PROMPT §8.4, M5-T01): one column per data key of ALL versions
 * (FieldCatalog), filters (date, status, submitter, group, field values), search, bulk exports and a
 * PDF ZIP. Rows follow the §8.6 "View submissions" row (ReportScope); the page needs `reports.view`.
 * The form is chosen with `?form=<id>` (links from the forms list and the inbox).
 */
class FormExplorer extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'explorer';

    /** Set from `?form=` on mount and re-validated on every request (ReportForms). */
    #[Locked]
    #[Url(as: 'form')]
    public ?int $formId = null;

    private bool $resolved = false;

    private ?Form $selected = null;

    private ?FieldCatalog $catalog = null;

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && Filament::getTenant() instanceof Organization && app(ReportForms::class)->mayReport($user);
    }

    public static function getNavigationGroup(): ?string
    {
        return __('reports.navigation.group');
    }

    public static function getNavigationLabel(): string
    {
        return __('reports.explorer.navigation');
    }

    public function getTitle(): string
    {
        $form = $this->selectedForm();

        return $form instanceof Form ? __('reports.explorer.title_form', ['form' => $form->name]) : __('reports.explorer.title');
    }

    public function mount(): void
    {
        $this->formId = $this->selectedForm()?->id;
    }

    public function selectedForm(): ?Form
    {
        if (! $this->resolved) {
            $forms = app(ReportForms::class);
            $options = $forms->options($this->viewer());
            $id = $this->formId !== null && array_key_exists($this->formId, $options) ? $this->formId : array_key_first($options);
            $this->selected = $forms->find($this->viewer(), $id);
            $this->resolved = true;
        }

        return $this->selected;
    }

    public function catalog(): FieldCatalog
    {
        $form = $this->selectedForm();

        return $this->catalog ??= $form instanceof Form ? FieldCatalog::forForm($form) : FieldCatalog::fromDefinitions([]);
    }

    public function table(Table $table): Table
    {
        return FormExplorerTable::configure($table, $this);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    protected function getHeaderActions(): array
    {
        return FormExplorerActions::header($this);
    }

    /** The table's current filters and search as report filters (exports, "save as report"). */
    public function reportFilters(): ReportFilters
    {
        return FormExplorerFilters::toReportFilters($this->tableFilters ?? [], $this->tableSearch, $this->catalog());
    }

    /** @param  list<string>|null  $uuids */
    public function startExport(ExportFormat $format, ?array $uuids = null): void
    {
        $form = $this->selectedForm();
        if (! $form instanceof Form) {
            return;
        }
        $max = max(1, (int) config('reports.exports.max_selected', 10000));
        if ($uuids !== null && count($uuids) > $max) {
            Notification::make()->warning()->title(__('reports.export.too_many', ['max' => $max]))->send();

            return;
        }
        app(StartExport::class)->handle($this->viewer(), $form, $format, $this->reportFilters(), self::timezone(), $uuids);

        Notification::make()->success()->title(__('reports.export.started.title'))
            ->body(__('reports.export.started.body'))->send();
    }

    public static function timezone(): string
    {
        return SubmissionResource::displayTimezone();
    }

    public function viewer(): User
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        return $user;
    }
}
