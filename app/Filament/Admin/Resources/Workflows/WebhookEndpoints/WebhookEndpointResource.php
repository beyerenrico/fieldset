<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Workflows\WebhookEndpoints;

use App\Domain\Identity\Models\User;
use App\Domain\Workflows\Actions\SaveWebhookEndpoint;
use App\Domain\Workflows\Models\WebhookEndpoint;
use App\Domain\Workflows\Webhooks\WebhookTarget;
use App\Filament\Admin\Resources\Workflows\WebhookEndpoints\Pages\ManageWebhookEndpoints;
use BackedEnum;
use Closure;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use InvalidArgumentException;

/** Outbound webhook endpoints of the `webhook` step (§8.3): URL, encrypted signing secret, active flag. */
class WebhookEndpointResource extends Resource
{
    protected static ?string $model = WebhookEndpoint::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'webhook-endpoints';

    public static function getNavigationGroup(): ?string
    {
        return __('workflows.navigation.group');
    }

    public static function getModelLabel(): string
    {
        return __('workflows.webhooks.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('workflows.webhooks.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('workflows.webhooks.name'))->required()->maxLength(120)->scopedUnique(ignoreRecord: true),
            TextInput::make('url')->label(__('workflows.webhooks.url'))->required()->url()->maxLength(2048)
                ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                    $problem = WebhookTarget::syntaxProblem(trim((string) $value));
                    if ($problem !== null) {
                        $fail($problem);
                    }
                }),
            TextInput::make('secret')->label(__('workflows.webhooks.secret'))->password()->revealable()->maxLength(255)
                ->helperText(__('workflows.webhooks.secret_help'))->dehydrated(),
            Toggle::make('active')->label(__('workflows.webhooks.active'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label(__('workflows.webhooks.name'))->searchable()->sortable(),
                TextColumn::make('url')->label(__('workflows.webhooks.url'))->limit(60)->fontFamily('mono'),
                IconColumn::make('active')->label(__('workflows.webhooks.active'))->boolean(),
            ])
            ->recordActions([
                EditAction::make()->using(fn (WebhookEndpoint $record, array $data): WebhookEndpoint => self::save($record, $data))
                    ->fillForm(fn (WebhookEndpoint $record): array => ['name' => $record->name, 'url' => $record->url, 'active' => $record->active, 'secret' => null]),
                DeleteAction::make(),
            ]);
    }

    /** @param  array<string, mixed>  $data */
    public static function save(?WebhookEndpoint $endpoint, array $data): WebhookEndpoint
    {
        /** @var User $actor */
        $actor = auth()->user();
        try {
            return app(SaveWebhookEndpoint::class)->handle($endpoint, [
                'name' => (string) ($data['name'] ?? ''), 'url' => (string) ($data['url'] ?? ''),
                'active' => (bool) ($data['active'] ?? true), 'secret' => is_string($data['secret'] ?? null) ? $data['secret'] : null,
            ], $actor);
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            throw new Halt($e->getMessage(), $e->getCode(), $e);
        }
    }

    public static function getPages(): array
    {
        return ['index' => ManageWebhookEndpoints::route('/')];
    }
}
