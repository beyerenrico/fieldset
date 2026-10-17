<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AuditLogs\Support;

use App\Domain\Audit\History\HistoryScope;
use App\Domain\Audit\Models\Activity;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Filament\Admin\Pages\Sso\SsoSettings;
use App\Filament\Admin\Resources\Forms\Pages\EditForm;
use App\Filament\Admin\Resources\Members\Pages\ManageMembers;
use App\Filament\Admin\Resources\Submissions\Pages\ViewSubmission;
use App\Filament\Admin\Resources\Workflows\Pages\EditWorkflow;
use Filament\Pages\Page;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

/**
 * Puts the History section (RecordHistory) at the end of the key record pages through a Filament render
 * hook, so the pages themselves stay unchanged (ADR 0038). The page being rendered supplies the record.
 */
final class HistoryHook
{
    public const string COMPONENT = 'audit-record-history';

    /** @var list<class-string<Page>> */
    public const array PAGES = [EditForm::class, ViewSubmission::class, EditWorkflow::class, ManageMembers::class, SsoSettings::class];

    public static function render(): ?View
    {
        $page = Livewire::current();
        if (! $page instanceof Page || ! app(TenantContext::class)->check() || ! Gate::allows('viewAny', Activity::class)) {
            return null;
        }
        $params = self::params($page);

        return $params === null ? null : view('filament.admin.audit-logs.history-hook', [
            'component' => self::COMPONENT,
            'params' => $params,
            'key' => 'audit-history-'.md5((string) json_encode($params)),
        ]);
    }

    /** @return array<string, string>|null */
    public static function params(Page $page): ?array
    {
        if ($page instanceof ManageMembers) {
            return ['scope' => HistoryScope::MEMBERS];
        }
        if ($page instanceof SsoSettings) {
            return ['scope' => HistoryScope::SSO];
        }
        $record = method_exists($page, 'getRecord') ? $page->getRecord() : null;
        if (! $record instanceof Model) {
            return null;
        }
        $scope = HistoryScope::forRecord($record);

        return ['scope' => $scope->kind, 'subjectType' => (string) $scope->subjectType, 'subjectId' => (string) $scope->subjectId];
    }
}
