<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Submissions\Schemas;

use App\Domain\Forms\Models\FormVersion;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Submissions\Admin\SubmissionDataPresenter;
use App\Domain\Submissions\Admin\ValueFormatter;
use App\Domain\Submissions\Enums\SubmissionStatus;
use App\Domain\Submissions\Models\Attachment;
use App\Domain\Submissions\Models\Submission;
use App\Filament\Admin\Resources\Submissions\SubmissionResource;
use App\Http\Controllers\Admin\AttachmentDownloadController;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

/**
 * Detail view: header facts, the data with labels of the pinned form version, documents (renditions with
 * download links and "send to customer" requests, M3-T04/T08) and the status history.
 */
final class SubmissionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $tz = SubmissionResource::displayTimezone();

        return $schema->columns(1)->components([
            Section::make(__('inbox.detail.overview'))->id('overview')->columns(['default' => 1, 'sm' => 2, 'lg' => 4])->schema([
                TextEntry::make('number')->label(__('inbox.column.number'))->placeholder('—')->fontFamily('mono'),
                TextEntry::make('form.name')->label(__('inbox.column.form')),
                TextEntry::make('formVersion.version')->label(__('inbox.column.version'))->prefix('v'),
                TextEntry::make('status')->label(__('inbox.column.status'))->badge()
                    ->formatStateUsing(fn (SubmissionStatus $state): string => $state->label())
                    ->color(fn (SubmissionStatus $state): string => SubmissionResource::statusColor($state)),
                TextEntry::make('creator.name')->label(__('inbox.column.submitter'))->placeholder('—'),
                TextEntry::make('created_at')->label(__('inbox.column.created_at'))->dateTime()->timezone($tz),
                TextEntry::make('submitted_at')->label(__('inbox.column.submitted_at'))->dateTime()->timezone($tz)->placeholder('—'),
                TextEntry::make('updated_at')->label(__('inbox.column.updated_at'))->dateTime()->timezone($tz),
            ]),
            Section::make(__('inbox.detail.data'))->id('data')->schema([
                View::make('filament.submissions.data')->viewData(fn (Submission $record): array => ['pages' => self::pages($record, $tz)]),
            ]),
            Section::make(__('inbox.detail.documents'))->id('documents')->schema([
                View::make('filament.submissions.documents')->viewData(fn (Submission $record): array => SubmissionDocuments::viewData(
                    $record, auth()->user() instanceof User ? auth()->user() : null, $tz,
                )),
            ]),
            Section::make(__('inbox.detail.events'))->id('events')->collapsible()->schema([
                View::make('filament.submissions.events')->viewData(fn (Submission $record): array => [
                    'events' => $record->events()->with('actor:id,name')->get(),
                    'timezone' => $tz,
                ]),
            ]),
        ]);
    }

    /** @return list<array{title: string, items: list<array<string, mixed>>}> */
    private static function pages(Submission $submission, string $tz): array
    {
        /** @var Organization $organization */
        $organization = Filament::getTenant();
        $version = FormVersion::query()->findOrFail($submission->form_version_id);
        $attachments = $submission->attachments()->get()->keyBy(fn (Attachment $a): string => strtolower($a->uuid))->all();

        $presenter = new SubmissionDataPresenter(
            new ValueFormatter(app()->getLocale(), $tz),
            $attachments,
            fn (Attachment $attachment): string => AttachmentDownloadController::signedUrl($organization, $attachment),
        );

        return $presenter->pages($version->definition, $submission->data);
    }
}
