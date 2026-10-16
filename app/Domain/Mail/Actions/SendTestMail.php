<?php

declare(strict_types=1);

namespace App\Domain\Mail\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Identity\Tenancy\TenantNotSetException;
use App\Domain\Mail\Mailables\TestMail;
use App\Domain\Mail\Models\MailMessage;

/** Queues a branded test mail to the acting user with the saved mail settings. */
final class SendTestMail
{
    public function __construct(private readonly TenantContext $tenant, private readonly QueueMail $queue) {}

    public function handle(User $user): MailMessage
    {
        $organization = $this->tenant->organization() ?? throw TenantNotSetException::make();

        return $this->queue->handle(new TestMail($organization->id, $user->locale ?? $organization->locale), $user->email, $user->name);
    }
}
