<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources\Organizations\Pages;

use App\Domain\Identity\Models\Organization;
use App\Filament\Platform\Resources\Organizations\OrganizationResource;
use App\Filament\Platform\Resources\Organizations\Tables\OrganizationMembersTable;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/** Members of one organization, with the audited "Impersonate" action (§8.12, ADR 0041). */
final class OrganizationMembers extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = OrganizationResource::class;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    /** @param  array<string, mixed>  $parameters */
    public static function canAccess(array $parameters = []): bool
    {
        return OrganizationResource::canViewAny();
    }

    public function getTitle(): string
    {
        return __('platform.members.title', ['organization' => $this->organization()->name]);
    }

    public function organization(): Organization
    {
        $record = $this->getRecord();
        assert($record instanceof Organization);

        return $record;
    }

    public function table(Table $table): Table
    {
        return OrganizationMembersTable::configure($table, $this->organization());
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }
}
