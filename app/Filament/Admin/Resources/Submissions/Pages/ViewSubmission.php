<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Submissions\Pages;

use App\Domain\Identity\Models\User;
use App\Domain\Submissions\Models\Submission;
use App\Domain\Workflows\Models\Task;
use App\Domain\Workflows\Tasks\TaskAccess;
use App\Filament\Admin\Resources\Submissions\Actions\RerenderAction;
use App\Filament\Admin\Resources\Submissions\Actions\ReviewActions;
use App\Filament\Admin\Resources\Submissions\Actions\RunWorkflowAction;
use App\Filament\Admin\Resources\Submissions\Actions\SendToCustomerAction;
use App\Filament\Admin\Resources\Submissions\SubmissionResource;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Detail of a submission (M3-T09) with "Send to customer" and "Re-render PDF", plus the M4 review actions
 * (approve / request changes on the open task) and "Run workflow" (manual workflows).
 */
class ViewSubmission extends ViewRecord
{
    protected static string $resource = SubmissionResource::class;

    public function getTitle(): string|Htmlable
    {
        return $this->getRecordTitle();
    }

    protected function getHeaderActions(): array
    {
        $task = static function (Submission $record): ?Task {
            $user = auth()->user();

            return $user instanceof User ? app(TaskAccess::class)->completableTaskFor($user, $record) : null;
        };

        return [
            ReviewActions::approve($task), ReviewActions::reject($task),
            SendToCustomerAction::make(), RerenderAction::make(), RunWorkflowAction::make(),
        ];
    }
}
