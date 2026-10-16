<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Workflows\Schemas;

use App\Domain\Mail\Templates\MailTemplate;
use App\Domain\Notifications\Notifier;
use App\Domain\Workflows\Actions\WorkflowDefinitionRules;
use App\Domain\Workflows\Enums\StepType;
use App\Filament\Admin\Resources\Workflows\Support\WorkflowOptions;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;

/** The steps Builder of the workflow editor: one block per step type (§8.3). */
final class WorkflowStepBlocks
{
    public static function builder(): Builder
    {
        return Builder::make('steps')->label(__('workflows.fields.steps'))
            ->blocks([self::sendEmail(), self::notify(), self::requestReview(), self::setStatus(), self::generateDocument(), self::webhook()])
            ->addActionLabel(__('workflows.fields.add_step'))
            ->collapsible()->reorderable()->blockNumbers()->minItems(1)->required()->default([]);
    }

    private static function block(StepType $type, string $icon): Block
    {
        return Block::make($type->value)->label($type->label())->icon($icon);
    }

    private static function sendEmail(): Block
    {
        $noOtherSource = fn (Get $get): bool => blank($get('to_fields')) && ! $get('to_submitter') && blank($get('to_group_ids'));

        return self::block(StepType::SendEmail, 'heroicon-o-envelope')->schema([
            TagsInput::make('to_addresses')->label(__('workflows.fields.to_addresses'))->helperText(__('workflows.fields.to_addresses_help'))
                ->nestedRecursiveRules(['email:filter'])
                ->required($noOtherSource)->validationMessages(['required' => __('workflows.validation.email_recipients_required')]),
            TagsInput::make('to_fields')->label(__('workflows.fields.to_fields'))->helperText(__('workflows.fields.to_fields_help'))
                ->suggestions(['customer.email']),
            Toggle::make('to_submitter')->label(__('workflows.fields.to_submitter')),
            Select::make('to_group_ids')->label(__('workflows.fields.to_group_ids'))->multiple()->options(WorkflowOptions::groups(...)),
            TextInput::make('subject')->label(__('workflows.fields.subject'))->required()->maxLength(MailTemplate::SUBJECT_MAX),
            Textarea::make('body')->label(__('workflows.fields.body'))->rows(6)->maxLength(MailTemplate::BODY_MAX)
                ->helperText(__('workflows.fields.body_help')),
            Select::make('document')->label(__('workflows.fields.document'))->required()->default('none')->options([
                'none' => __('workflows.fields.document_none'),
                'attachment' => __('workflows.fields.document_attachment'),
                'link' => __('workflows.fields.document_link'),
            ]),
        ]);
    }

    private static function notify(): Block
    {
        $noOtherRecipient = fn (Get $get): bool => blank($get('group_ids')) && blank($get('roles'));

        return self::block(StepType::Notify, 'heroicon-o-bell')->schema([
            Select::make('user_ids')->label(__('workflows.fields.user_ids'))->helperText(__('workflows.fields.notify_recipients_help'))
                ->multiple()->searchable()->options(WorkflowOptions::members(...))
                ->required($noOtherRecipient)->validationMessages(['required' => __('workflows.validation.notify_recipients_required')]),
            Select::make('group_ids')->label(__('workflows.fields.group_ids'))->multiple()->options(WorkflowOptions::groups(...)),
            Select::make('roles')->label(__('workflows.fields.roles'))->multiple()->options(WorkflowOptions::roles(...)),
            CheckboxList::make('channels')->label(__('workflows.fields.channels'))->default([Notifier::CHANNEL_DATABASE, Notifier::CHANNEL_MAIL])
                ->required()->minItems(1)->validationMessages(['required' => __('workflows.validation.channels_required')])->options([
                    Notifier::CHANNEL_DATABASE => __('workflows.fields.channel_database'),
                    Notifier::CHANNEL_MAIL => __('workflows.fields.channel_mail'),
                ]),
            Textarea::make('message')->label(__('workflows.fields.message'))->required()->rows(3)->maxLength(1000),
        ]);
    }

    private static function requestReview(): Block
    {
        return self::block(StepType::RequestReview, 'heroicon-o-clipboard-document-check')->schema([
            Radio::make('assignee_type')->label(__('workflows.fields.assignee_type'))->inline()->live()->required()->default('group')->options([
                'group' => __('workflows.fields.assignee_group'),
                'user' => __('workflows.fields.assignee_user'),
            ]),
            Select::make('assignee_group_id')->label(__('workflows.fields.assignee_group_id'))->options(WorkflowOptions::groups(...))
                ->visible(fn (Get $get): bool => $get('assignee_type') !== 'user')->required(fn (Get $get): bool => $get('assignee_type') !== 'user'),
            Select::make('assignee_user_id')->label(__('workflows.fields.assignee_user_id'))->searchable()->options(WorkflowOptions::members(...))
                ->visible(fn (Get $get): bool => $get('assignee_type') === 'user')->required(fn (Get $get): bool => $get('assignee_type') === 'user'),
            TextInput::make('sla_hours')->label(__('workflows.fields.sla_hours'))->integer()->minValue(1)
                ->maxValue((int) config('workflows.review.max_sla_hours')),
        ]);
    }

    private static function setStatus(): Block
    {
        return self::block(StepType::SetStatus, 'heroicon-o-flag')->schema([
            // Only completed / void: review states are changed by "Request review" and the decision (ADR 0032).
            Select::make('status')->label(__('workflows.fields.status'))->helperText(__('workflows.fields.status_help'))->required()
                ->options(fn (): array => WorkflowOptions::statusOptions(WorkflowDefinitionRules::SET_STATUS_TARGETS)),
        ]);
    }

    private static function generateDocument(): Block
    {
        return self::block(StepType::GenerateDocument, 'heroicon-o-document-text')->schema([
            Select::make('design_key')->label(__('workflows.fields.design_key'))->placeholder(__('workflows.fields.design_default'))
                ->options(WorkflowOptions::designs(...)),
        ]);
    }

    private static function webhook(): Block
    {
        return self::block(StepType::Webhook, 'heroicon-o-globe-alt')->schema([
            Select::make('endpoint_id')->label(__('workflows.fields.endpoint_id'))->required()->options(WorkflowOptions::endpoints(...)),
        ]);
    }
}
