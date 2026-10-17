<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\ApiTokens;

use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tokens\Actions\CreateApiToken;
use App\Domain\Identity\Tokens\ApiTokenAccess;
use App\Domain\Identity\Tokens\TokenAbility;
use App\Domain\Identity\Tokens\TokenExpiry;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

/**
 * Integration tokens of the current organization (BUILD_PROMPT §10, ADR 0043): create (name, abilities,
 * expiry; the plain text is shown once in a modal), list and revoke. Panel users manage their own tokens;
 * Owners and Admins (`members.manage`) see and revoke every token of the organization.
 */
class ApiTokens extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCommandLine;

    protected static ?int $navigationSort = 50;

    protected static ?string $slug = 'api-tokens';

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();
        $organization = Filament::getTenant();

        return $user instanceof User && $organization instanceof Organization
            && app(ApiTokenAccess::class)->canUse($user, $organization);
    }

    public static function getNavigationGroup(): ?string
    {
        return __('identity.navigation.team');
    }

    public static function getNavigationLabel(): string
    {
        return __('api_tokens.navigation');
    }

    public function getTitle(): string
    {
        return __('api_tokens.title');
    }

    public function getSubheading(): string
    {
        return __('api_tokens.subheading');
    }

    public function table(Table $table): Table
    {
        return ApiTokensTable::configure($table, $this->user(), $this->organization());
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    /** @return list<Action> */
    protected function getHeaderActions(): array
    {
        return [$this->createAction()];
    }

    public function createAction(): Action
    {
        return Action::make('create')
            ->label(__('api_tokens.form.create'))
            ->icon(Heroicon::OutlinedPlus)
            ->modalHeading(__('api_tokens.form.create'))
            ->modalSubmitActionLabel(__('api_tokens.form.submit'))
            ->authorize(fn (): bool => app(ApiTokenAccess::class)->canCreate($this->user(), $this->organization()))
            ->fillForm(['expires_in' => TokenExpiry::defaultChoice()])
            ->schema([
                TextInput::make('name')->label(__('api_tokens.form.name'))->helperText(__('api_tokens.form.name_help'))
                    ->required()->maxLength(CreateApiToken::NAME_MAX),
                CheckboxList::make('abilities')->label(__('api_tokens.form.abilities'))->helperText(__('api_tokens.form.abilities_help'))
                    ->options(TokenAbility::options())
                    ->descriptions(array_combine(TokenAbility::values(), array_map(fn (TokenAbility $a): string => $a->description(), TokenAbility::cases())))
                    ->required()->columns(['md' => 2]),
                Select::make('expires_in')->label(__('api_tokens.form.expires_in'))
                    ->options(TokenExpiry::options())->required()->selectablePlaceholder(false),
            ])
            ->action(function (array $data, Schema $schema): void {
                $plainTextToken = $this->issue($data, $schema);
                $this->replaceMountedAction('showToken', ['token' => $plainTextToken]);
            });
    }

    /** Shows the plain text of a just-created token; it cannot be shown again. */
    public function showTokenAction(): Action
    {
        return Action::make('showToken')
            ->modalHeading(__('api_tokens.created.title'))
            ->modalDescription(__('api_tokens.created.description'))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('api_tokens.created.done'))
            ->closeModalByClickingAway(false)
            ->fillForm(fn (array $arguments): array => [
                'token' => is_string($arguments['token'] ?? null) ? $arguments['token'] : '',
                'organization' => (string) $this->organization()->id,
            ])
            ->schema([
                TextInput::make('token')->label(__('api_tokens.created.token'))->readOnly()->copyable()
                    ->extraInputAttributes(['class' => 'font-mono']),
                TextInput::make('organization')->label(__('api_tokens.created.organization'))->readOnly()->copyable(),
            ]);
    }

    /** @param  array<string, mixed>  $data */
    private function issue(array $data, Schema $schema): string
    {
        $abilities = is_array($data['abilities'] ?? null) ? array_values($data['abilities']) : [];

        try {
            return app(CreateApiToken::class)->handle(
                $this->user(), $this->organization(), (string) ($data['name'] ?? ''), $abilities,
                TokenExpiry::resolve((string) ($data['expires_in'] ?? TokenExpiry::defaultChoice())),
            )->plainTextToken;
        } catch (ValidationException $e) {
            $messages = [];
            foreach ($e->errors() as $field => $errors) {
                $key = $field === 'expires_at' ? 'expires_in' : explode('.', $field)[0];
                $messages[$schema->getStatePath().'.'.$key] = $errors;
            }
            throw ValidationException::withMessages($messages);
        }
    }

    private function user(): User
    {
        $user = Filament::auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function organization(): Organization
    {
        $organization = Filament::getTenant();
        abort_unless($organization instanceof Organization, 403);

        return $organization;
    }
}
