<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Submissions\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Mail\Actions\RequestCustomerSend;
use App\Domain\Mail\CustomerSend\CustomerRecipient;
use App\Domain\Mail\Enums\DocumentSendStatus;
use App\Domain\Submissions\Models\Submission;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

/**
 * "Send to customer" in the inbox detail (M3-T08): the same action and rules as
 * POST /submissions/{uuid}/send (SubmissionPolicy::sendToCustomer), prefilled from the
 * customer.email / customer.name values.
 */
final class SendToCustomerAction
{
    public static function make(): Action
    {
        return Action::make('sendToCustomer')->label(__('mail.customer_send.action'))->icon('heroicon-o-paper-airplane')
            ->authorize(fn (Submission $record): bool => auth()->user()?->can('sendToCustomer', $record) ?? false)
            ->modalDescription(__('mail.customer_send.action_help'))
            ->modalSubmitActionLabel(__('mail.customer_send.submit'))
            ->fillForm(fn (Submission $record): array => CustomerRecipient::defaults($record))
            ->schema([
                TextInput::make('email')->label(__('mail.customer_send.email'))->email()->required()->maxLength(CustomerRecipient::EMAIL_MAX),
                TextInput::make('name')->label(__('mail.customer_send.name'))->maxLength(CustomerRecipient::NAME_MAX),
            ])
            ->action(function (array $data, Submission $record): void {
                /** @var User $user */
                $user = auth()->user();
                try {
                    $recipient = CustomerRecipient::resolve($record, $data['email'] ?? null, $data['name'] ?? null);
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title($e->validator->errors()->first())->send();

                    return;
                }
                $send = app(RequestCustomerSend::class)->handle($user, $record, $recipient);

                $key = $send->status === DocumentSendStatus::Queued ? 'mail.customer_send.queued' : 'mail.customer_send.waiting';
                Notification::make()->success()->title(__($key, ['email' => $recipient->email]))->send();
            });
    }
}
