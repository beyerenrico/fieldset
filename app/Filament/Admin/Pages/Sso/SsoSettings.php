<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Sso;

use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Sso\Admin\DeleteIdentityProvider;
use App\Domain\Identity\Sso\Admin\SaveIdentityProvider;
use App\Domain\Identity\Sso\Admin\SetSsoEnforcement;
use App\Domain\Identity\Sso\Enums\IdentityProviderType;
use App\Domain\Identity\Sso\Enums\SsoClient;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * Single sign-on settings of the organization (BUILD_PROMPT §8.7, ADR 0036): provider status and URLs, the
 * provider configuration, DNS-verified domains, claim mappings and enforcement with the break-glass Owner.
 * "Users, roles, groups, SSO" capability (members.manage; IdentityProviderPolicy). Domain actions do the
 * validation and auditing; this page maps their errors onto its forms.
 */
class SsoSettings extends Page implements HasTable
{
    use InteractsWithTable;

    /** @var array<string, mixed>|null */
    public ?array $provider = [];

    /** @var array<string, mixed>|null */
    public ?array $mappings = [];

    /** @var array<string, mixed>|null */
    public ?array $enforcement = [];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?int $navigationSort = 40;

    protected static ?string $slug = 'sso';

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && Filament::getTenant() instanceof Organization && $user->can('viewAny', IdentityProvider::class);
    }

    public static function getNavigationGroup(): ?string
    {
        return __('identity.navigation.team');
    }

    public static function getNavigationLabel(): string
    {
        return __('sso_admin.navigation');
    }

    public function getTitle(): string
    {
        return __('sso_admin.title');
    }

    public function getSubheading(): string
    {
        return __('sso_admin.subheading');
    }

    public function mount(): void
    {
        $this->fillForms();
    }

    public function providerForm(Schema $schema): Schema
    {
        return $schema->components(ProviderSchema::components($this->organization(), $this->identityProvider()))->statePath('provider');
    }

    public function mappingsForm(Schema $schema): Schema
    {
        return $schema->components(MappingsSchema::components($this->organization()))->statePath('mappings');
    }

    public function enforcementForm(Schema $schema): Schema
    {
        $provider = $this->identityProvider();

        return $schema->components($provider instanceof IdentityProvider ? EnforcementSchema::components($this->organization(), $provider, $this->user()) : [])
            ->statePath('enforcement');
    }

    public function table(Table $table): Table
    {
        return DomainsTable::configure($table);
    }

    public function content(Schema $schema): Schema
    {
        $hasProvider = $this->identityProvider() instanceof IdentityProvider;

        return $schema->components([
            ...StatusSchema::components($this->identityProvider()),
            $this->formSection('providerForm', 'saveProvider', 'sso_admin.provider.save'),
            EmbeddedTable::make(),
            $this->formSection('mappingsForm', 'saveMappings', 'sso_admin.mappings.save')->visible($hasProvider),
            $this->formSection('enforcementForm', 'saveEnforcement', 'sso_admin.enforcement.save')->visible($hasProvider),
        ]);
    }

    public function saveProvider(): void
    {
        abort_unless($this->can($this->identityProvider() instanceof IdentityProvider ? 'update' : 'create'), 403);
        $state = $this->getSchema('providerForm')?->getState() ?? [];
        $secret = filled($state['client_secret'] ?? null) ? (string) $state['client_secret'] : null;

        $this->mapErrors('provider', fn (): IdentityProvider => app(SaveIdentityProvider::class)->handle(
            $this->organization(),
            IdentityProviderType::tryFrom((string) ($state['type'] ?? '')) ?? IdentityProviderType::Oidc,
            (string) ($state['name'] ?? ''),
            Arr::except($state, ['type', 'name', 'client_secret']),
            $secret,
            $this->user(),
        ));

        $this->fillForms();
        Notification::make()->success()->title(__('sso_admin.provider.saved'))->send();
    }

    public function saveMappings(): void
    {
        $provider = $this->identityProvider();
        abort_unless($provider instanceof IdentityProvider && $this->can('update'), 403);
        $rows = $this->getSchema('mappingsForm')?->getState()['rows'] ?? [];

        MappingsSchema::sync($provider, is_array($rows) ? $rows : [], $this->user(), 'mappings');

        $this->getSchema('mappingsForm')?->fill(MappingsSchema::fill($provider));
        Notification::make()->success()->title(__('sso_admin.mappings.saved'))->send();
    }

    public function saveEnforcement(): void
    {
        $provider = $this->identityProvider();
        abort_unless($provider instanceof IdentityProvider && $this->can('update'), 403);
        $state = $this->getSchema('enforcementForm')?->getState() ?? [];
        $breakGlass = is_numeric($state['break_glass_user_id'] ?? null)
            ? $this->organization()->members()->whereKey((int) $state['break_glass_user_id'])->first()
            : null;

        $this->mapErrors('enforcement', fn () => app(SetSsoEnforcement::class)->handle(
            $provider, (bool) ($state['enforce_sso'] ?? false), $breakGlass instanceof User ? $breakGlass : null, $this->user(),
        ));

        $this->fillForms();
        Notification::make()->success()->title(__('sso_admin.enforcement.saved'))->send();
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('testLogin')
                ->label(__('sso_admin.actions.test_login'))
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->tooltip(__('sso_admin.actions.test_login_help'))
                ->url(fn (): ?string => $this->identityProvider()?->redirectUrl(SsoClient::Admin, test: true))
                ->openUrlInNewTab()
                ->visible(fn (): bool => $this->identityProvider() instanceof IdentityProvider && $this->can('update')),
            Action::make('deleteProvider')
                ->label(__('sso_admin.actions.delete'))
                ->icon(Heroicon::OutlinedTrash)
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription(__('sso_admin.actions.delete_confirm'))
                ->visible(fn (): bool => $this->identityProvider() instanceof IdentityProvider && $this->can('delete'))
                ->action(function (): void {
                    $provider = $this->identityProvider();
                    abort_unless($provider instanceof IdentityProvider && $this->can('delete'), 403);
                    try {
                        app(DeleteIdentityProvider::class)->handle($provider, $this->user());
                    } catch (ValidationException $e) {
                        Notification::make()->danger()->title($e->validator->errors()->first())->send();

                        return;
                    }
                    $this->fillForms();
                    Notification::make()->success()->title(__('sso_admin.actions.deleted'))->send();
                }),
        ];
    }

    private function formSection(string $schema, string $handler, string $label): Form
    {
        return Form::make([EmbeddedSchema::make($schema)])
            ->id($schema)
            ->livewireSubmitHandler($handler)
            ->footer([
                Actions::make([Action::make($handler)->label(__($label))->submit($handler)])->key($schema.'-actions'),
            ]);
    }

    /** Runs a domain action and moves its validation errors under the form's state path. */
    private function mapErrors(string $statePath, \Closure $action): mixed
    {
        try {
            return $action();
        } catch (ValidationException $e) {
            $messages = [];
            foreach ($e->errors() as $key => $errors) {
                $field = str_starts_with($key, 'scopes.') ? 'scopes' : $key;
                $messages["{$statePath}.{$field}"] = [...($messages["{$statePath}.{$field}"] ?? []), ...$errors];
            }

            throw ValidationException::withMessages($messages);
        }
    }

    private function fillForms(): void
    {
        $provider = $this->identityProvider();
        foreach (['content', 'providerForm', 'mappingsForm', 'enforcementForm'] as $schema) {
            $this->cacheSchema($schema, null); // their components depend on the stored provider
        }
        $this->getSchema('providerForm')?->fill(ProviderSchema::fill($provider));
        $this->getSchema('mappingsForm')?->fill(MappingsSchema::fill($provider));
        $this->getSchema('enforcementForm')?->fill(EnforcementSchema::fill($provider));
    }

    private function identityProvider(): ?IdentityProvider
    {
        return IdentityProvider::query()->first();
    }

    private function can(string $ability): bool
    {
        $provider = $this->identityProvider();

        return in_array($ability, ['create', 'viewAny'], true) || ! $provider instanceof IdentityProvider
            ? $this->user()->can($ability, IdentityProvider::class)
            : $this->user()->can($ability, $provider);
    }

    private function organization(): Organization
    {
        $tenant = Filament::getTenant();
        abort_unless($tenant instanceof Organization, 404);

        return $tenant;
    }

    private function user(): User
    {
        $user = Filament::auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
