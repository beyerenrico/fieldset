<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AuditLogs\Schemas;

use App\Domain\Audit\AuditEntryView;
use App\Domain\Audit\AuditLabels;
use App\Domain\Audit\Models\Activity;
use App\Filament\Admin\Resources\Submissions\SubmissionResource;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

/** Detail of one audit entry (§8.11 fields), shared by the audit log and the History sections. */
final class AuditEntryInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('created_at')->label(__('audit.fields.time'))->isoDateTime('L LT')->timezone(SubmissionResource::displayTimezone()),
            TextEntry::make('event')->label(__('audit.fields.event'))->formatStateUsing(fn (?string $state): string => AuditLabels::event($state)),
            TextEntry::make('actor')->label(__('audit.fields.actor'))->state(fn (Activity $record): string => AuditLabels::actor($record)),
            TextEntry::make('impersonator')->label(__('audit.fields.impersonator'))->state(fn (Activity $record): ?string => AuditLabels::impersonator($record))
                ->visible(fn (Activity $record): bool => AuditLabels::impersonator($record) !== null)->color('warning'),
            TextEntry::make('actor_type')->label(__('audit.fields.actor_type'))->formatStateUsing(fn (string $state): string => (string) __('audit.actor_types.'.$state)),
            TextEntry::make('subject')->label(__('audit.fields.subject'))->state(fn (Activity $record): string => AuditLabels::subject($record)),
            TextEntry::make('ip_address')->label(__('audit.fields.ip_address'))->placeholder('—'),
            TextEntry::make('user_agent')->label(__('audit.fields.user_agent'))->placeholder('—')->columnSpanFull(),
            TextEntry::make('request_id')->label(__('audit.fields.request_id'))->placeholder('—')->copyable(),
            TextEntry::make('chain')->label(__('audit.fields.chain'))
                ->state(fn (Activity $record): string => $record->chain_seq === null ? (string) __('audit.fields.unsealed') : '#'.$record->chain_seq.' · '.substr((string) $record->hash, 0, 16).'…'),
            KeyValueEntry::make('changes')->label(__('audit.fields.changes'))->keyLabel(__('audit.fields.attribute'))->valueLabel(__('audit.fields.value'))
                ->state(fn (Activity $record): array => AuditEntryView::changes($record, SubmissionResource::displayTimezone()))
                ->visible(fn (Activity $record): bool => AuditEntryView::changes($record, SubmissionResource::displayTimezone()) !== [])->columnSpanFull(),
            KeyValueEntry::make('details')->label(__('audit.fields.properties'))->keyLabel(__('audit.fields.property'))->valueLabel(__('audit.fields.value'))
                ->state(fn (Activity $record): array => AuditEntryView::properties($record, SubmissionResource::displayTimezone()))
                ->visible(fn (Activity $record): bool => AuditEntryView::properties($record, SubmissionResource::displayTimezone()) !== [])->columnSpanFull(),
        ])->columns(2);
    }
}
