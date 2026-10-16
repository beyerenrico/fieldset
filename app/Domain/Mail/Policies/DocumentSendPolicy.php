<?php

declare(strict_types=1);

namespace App\Domain\Mail\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Mail\Models\DocumentSend;
use App\Policies\TenantPolicy;

/**
 * Send requests are visible to whoever may see the submission. They are created only through
 * RequestCustomerSend (authorized by SubmissionPolicy::sendToCustomer) and never edited by users.
 */
final class DocumentSendPolicy extends TenantPolicy
{
    public function view(User $user, DocumentSend $send): bool
    {
        return $this->owns($send) && $user->can('view', $send->submission);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, DocumentSend $send): bool
    {
        return false;
    }

    public function delete(User $user, DocumentSend $send): bool
    {
        return false;
    }
}
