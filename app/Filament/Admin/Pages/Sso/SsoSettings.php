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
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/**
 * Single sign-on settings of the organization (BUILD_PROMPT §8.7, ADR 0036): provider status and URLs, the
 * provider configuration, DNS-verified domains, claim mappings and enforcement with the break-glass Owner.
 * "Users, roles, groups, SSO" capability (members.manage; IdentityProviderPolicy); setting up the provider,
 * its Owner-only settings and switching enforcement off need Owner level (`create` / `manageSecurity`).
 * Domain actions do the validation and auditing; this page maps their errors onto its forms. A test sign-in
 * returns here with its result (TestResultNotification); before the first save the callback URLs of the
 * pending provider id are shown.
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

    /** Public id of the provider before its first save: its callback URLs are shown and then kept. */
    #[Locked]
    public ?string $pendingUuid = null;

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
        $this->pendingUuid = (string) Str::uuid();
        $this->fillForms();
        TestResultNotification::fromSession($this->identityProvider())?->send();
    }

    public function providerForm(Schema $schema): Schema
    {
        return $schema->components(ProviderSchema::components($this->organization(), $this->identityProvider(), $this->user(), $this->isOwnerLevel()))
            ->statePath('provider');
    }

    public function mappingsForm(Schema $schema): Schema
    {
        return $schema->components(MappingsSchema::components($this->organization()))->statePath('mappings');
    }

    public function enforcementForm(Schema $schema): Schema
    {
        $provider = $this->identityProvider();

        return $schema->components($provider instanceof IdentityProvider
            ? EnforcementSchema::components($this->organization(), $provider, $this->user(), $this->isOwnerLevel())
            : [])->statePath('enforcement');
    }

    public function table(Table $table): Table
    {
        return DomainsTable::configure($table);
    }

    public function content(Schema $schema): Schema
    {
        $provider = $this->identityProvider();
        $hasProvider = $provider instanceof IdentityProvider;

        return $schema->components([
            ...StatusSchema::components($provider, $this->pendingProvider(), fn (): string => (string) ($this->provider['type'] ?? 'oidc')),
            $this->formSection('providerForm', 'saveProvider', 'sso_admin.provider.save', Action::make('saveProvider')
                ->label(__('sso_admin.provider.save'))->submit('saveProvider')->visible($hasProvider || $this->isOwnerLevel())),
            EmbeddedTable::make(),
            $this->formSection('mappingsForm', 'saveMappings', 'sso_admin.mappings.save')->visible($hasProvider),
            $this->formSection('enforcementForm', 'saveEnforcement', 'sso_admin.enforcement.save', $this->saveEnforcementAction())->visible($hasProvider),
        ]);
    }

    public function saveProvider(): void
    {
        $existing = $this->identityProvider();
        abort_unless($this->can($existing instanceof IdentityProvider ? 'update' : 'create'), 403);
        $state = $this->getSchema('providerForm')?->getState() ?? [];
        if ($existing instanceof IdentityProvider && ! $this->isOwnerLevel()) {
            $state = [...$state, ...ProviderSchema::ownerOnlyState($existing)]; // read-only fields keep their stored values
        }
        $secret = filled($state['client_secret'] ?? null) ? (string) $state['client_secret'] : null;

        $this->mapErrors('provider', fn (): IdentityProvider => app(SaveIdentityProvider::class)->handle(
            $this->organization(),
            IdentityProviderType::tryFrom((string) ($state['type'] ?? '')) ?? IdentityProviderType::Oidc,
            (string) ($state['name'] ?? ''),
            Arr::except($state, ['type', 'name', 'client_secret']),
            $secret,
            $this->user(),
            $existing instanceof IdentityProvider ? null : $this->pendingUuid,
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
        $on = array_key_exists('enforce_sso', $state) ? (bool) $state['enforce_sso'] : $provider->enforce_sso; // read-only below Owner level

        $this->mapErrors('enforcement', fn () => app(SetSsoEnforcement::class)->handle(
            $provider, $on, $this->member($state['break_glass_user_id'] ?? null), $this->user(),
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

    private function formSection(string $schema, string $handler, string $label, ?Action $action = null): Form
    {
        return Form::make([EmbeddedSchema::make($schema)])
            ->id($schema)
            ->livewireSubmitHandler($handler)
            ->footer([
                Actions::make([$action ?? Action::make($handler)->label(__($label))->submit($handler)])->key($schema.'-actions'),
            ]);
    }

    /** Saves the enforcement form; switching it on asks for confirmation first (EnforcementSchema::confirmation). */
    private function saveEnforcementAction(): Action
    {
        $confirmation = function (): ?string {
            $provider = $this->identityProvider();

            return $provider instanceof IdentityProvider ? EnforcementSchema::confirmation(
                $provider, $this->user(), $this->enforcement['enforce_sso'] ?? null, $this->member($this->enforcement['break_glass_user_id'] ?? null),
            ) : null;
        };

        return Action::make('saveEnforcement')
            ->label(__('sso_admin.enforcement.save'))
            ->requiresConfirmation(fn (): bool => $confirmation() !== null)
            ->modalHeading(__('sso_admin.enforcement.confirm_heading'))
            ->modalDescription($confirmation)
            ->modalSubmitActionLabel(__('sso_admin.enforcement.confirm_submit'))
            ->action(fn () => $this->saveEnforcement());
    }

    /** The provider-to-be: its pending uuid, for the callback URLs before the first save. */
    private function pendingProvider(): ?IdentityProvider
    {
        return $this->pendingUuid !== null && $this->isOwnerLevel() ? (new IdentityProvider)->forceFill(['uuid' => $this->pendingUuid]) : null;
    }

    private function member(mixed $id): ?User
    {
        $member = is_numeric($id) ? $this->organization()->members()->whereKey((int) $id)->first() : null;

        return $member instanceof User ? $member : null;
    }

    /** Owner level: sets up the provider, changes its Owner-only settings, switches enforcement off. */
    private function isOwnerLevel(): bool
    {
        $provider = $this->identityProvider();

        return $provider instanceof IdentityProvider ? $this->user()->can('manageSecurity', $provider) : $this->user()->can('create', IdentityProvider::class);
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
