<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Submissions\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Mail\Actions\RequestCustomerSend;
use App\Domain\Mail\CustomerSend\CustomerRecipient;
use App\Domain\Mail\CustomerSend\CustomerSendLimitExceeded;
use App\Domain\Mail\Enums\DocumentSendStatus;
use App\Domain\Submissions\Models\Submission;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Illuminate\Validation\ValidationException;

/**
 * "Send to customer" in the inbox detail (M3-T08): the same action and rules as
 * POST /submissions/{uuid}/send (SubmissionPolicy::sendToCustomer), prefilled from the
 * customer.email / customer.name values. The email uses the server's `email:filter` rule; a server-side
 * rejection keeps the modal open with the error on its field. A cleared name means "no name".
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
                TextInput::make('email')->label(__('mail.customer_send.email'))->email()->rule('email:filter')->required()
                    ->maxLength(CustomerRecipient::EMAIL_MAX),
                TextInput::make('name')->label(__('mail.customer_send.name'))->maxLength(CustomerRecipient::NAME_MAX),
            ])
            ->action(function (array $data, Submission $record, Schema $schema): void {
                /** @var User $user */
                $user = auth()->user();
                $recipient = self::recipient($record, $data, $schema);
                try {
                    $send = app(RequestCustomerSend::class)->handle($user, $record, $recipient);
                } catch (CustomerSendLimitExceeded $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    throw new Halt($e->getMessage(), $e->getCode(), $e);
                }

                $key = $send->status === DocumentSendStatus::Queued ? 'mail.customer_send.queued' : 'mail.customer_send.waiting';
                Notification::make()->success()->title(__($key, ['email' => $recipient->email]))->send();
            });
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException with the errors on the modal's fields
     */
    private static function recipient(Submission $record, array $data, Schema $schema): CustomerRecipient
    {
        try {
            return CustomerRecipient::resolve($record, $data['email'] ?? null, $data['name'] ?? null, nameProvided: true);
        } catch (ValidationException $e) {
            $messages = [];
            foreach ($e->errors() as $field => $errors) {
                $messages[$schema->getStatePath().'.'.$field] = $errors;
            }

            throw ValidationException::withMessages($messages);
        }
    }
}
