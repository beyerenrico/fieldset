<?php

declare(strict_types=1);

namespace App\Domain\Mail\CustomerSend;

use App\Domain\Mail\Models\DocumentSend;

/** The `send` object of `POST /submissions/{uuid}/send` (docs/api/field-app-v1.md). */
final class DocumentSendPresenter
{
    /** @return array{id: int, status: string, recipient: array{email: string, name: string|null}} */
    public static function present(DocumentSend $send): array
    {
        return [
            'id' => $send->id,
            'status' => $send->status->value,
            'recipient' => ['email' => $send->recipient_email, 'name' => $send->recipient_name],
        ];
    }
}
