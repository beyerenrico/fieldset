<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Domain\Documents\Branding\Branding;
use App\Domain\Documents\Branding\StoreLogo;
use App\Domain\Documents\Branding\UpdateBranding;
use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use InvalidArgumentException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Organization branding (BUILD_PROMPT §8.5): logo, primary/accent colors, a bundled OFL font and
 * company details, applied to PDFs and mails. Requires the organization-settings capability
 * (organization.manage, ADR 0017).
 *
 * @property-read Schema $form
 */
class ManageBranding extends Page
{
    /** @var array<string, mixed>|null */
    public ?array $data = [];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaintBrush;

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'branding';

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();
        $tenant = Filament::getTenant();

        return $user instanceof User && $tenant instanceof Organization
            && $user->isActive() && $user->hasPermissionIn($tenant, Permission::OrganizationManage);
    }

    public static function getNavigationGroup(): ?string
    {
        return __('forms.navigation.group');
    }

    public static function getNavigationLabel(): string
    {
        return __('documents.branding.title');
    }

    public function getTitle(): string
    {
        return __('documents.branding.title');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $branding = Branding::forOrganization($this->organization());
        $this->form->fill([
            'primary_color' => $branding->primaryColor,
            'accent_color' => $branding->accentColor,
            'font' => $branding->font,
            'logo_path' => $branding->logoPath,
            'company' => $branding->company,
        ]);
    }

    /** Re-authorize on every Livewire request: permissions may change while the page stays open. */
    public function hydrate(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('documents.branding.look'))->schema([
                FileUpload::make('logo_path')->label(__('documents.branding.logo'))
                    ->helperText(__('documents.branding.logo_help', ['kb' => (int) config('documents.logo_max_kb', 2048)]))
                    ->image()->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])
                    ->maxSize((int) config('documents.logo_max_kb', 2048))
                    ->disk((string) config('documents.disk', 'local'))->visibility('private')
                    ->saveUploadedFileUsing(fn (TemporaryUploadedFile $file): string => app(StoreLogo::class)->handle($this->organization(), (string) $file->get())),
                ColorPicker::make('primary_color')->label(__('documents.branding.primary_color'))->hex()->required()->regex('/^#[0-9a-fA-F]{6}$/'),
                ColorPicker::make('accent_color')->label(__('documents.branding.accent_color'))->hex()->required()->regex('/^#[0-9a-fA-F]{6}$/'),
                Select::make('font')->label(__('documents.branding.font'))->options(Branding::FONTS)->required()->native(false)
                    ->helperText(__('documents.branding.font_help')),
            ])->columns(2),
            Section::make(__('documents.branding.company'))->schema(array_map(
                static fn (string $key): Textarea|\Filament\Forms\Components\TextInput => in_array($key, ['address', 'legal_footer'], true)
                    ? Textarea::make("company.{$key}")->label(__("documents.branding.company_fields.{$key}"))->rows(3)->maxLength(UpdateBranding::COMPANY_VALUE_MAX)
                    : TextInput::make("company.{$key}")->label(__("documents.branding.company_fields.{$key}"))->maxLength(UpdateBranding::COMPANY_VALUE_MAX),
                Branding::COMPANY_KEYS,
            ))->columns(2),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([Actions::make([Action::make('save')->label(__('documents.branding.save'))->submit('save')->keyBindings(['mod+s'])])]),
        ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        try {
            /** @var array<string, mixed> $state */
            $state = $this->form->getState();
            app(UpdateBranding::class)->handle($this->organization(), $state);
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }
        Notification::make()->success()->title(__('documents.branding.saved'))->send();
    }

    private function organization(): Organization
    {
        /** @var Organization $organization */
        $organization = Filament::getTenant();

        return $organization;
    }
}
