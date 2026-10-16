<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Listeners;

use App\Domain\Forms\Events\FormPublished;
use App\Domain\Forms\Models\Form;
use App\Domain\Forms\Models\FormVersion;
use App\Domain\Identity\Enums\MembershipStatus;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Notifications\NotificationEvent;
use App\Domain\Notifications\NotificationMessage;
use App\Domain\Notifications\Notifier;
use App\Domain\Submissions\Support\FormAccessResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;

/**
 * "New form available" (§8.9): after a version is published, everyone who may fill the form in the
 * Field App (FormAccessResolver: grants, or forms.fill_all) is notified, except the publisher.
 * Queued: resolving access for every member must not slow down the publish request. A version that
 * is no longer current by the time the listener runs (published again meanwhile) is skipped.
 */
final readonly class NotifyFormAvailable implements ShouldQueue
{
    /** Field App catalogue of fillable forms. */
    public const string URL = '/app/forms';

    public function __construct(private TenantContext $tenant, private FormAccessResolver $access, private Notifier $notifier) {}

    public function handle(FormPublished $event): void
    {
        $this->tenant->run($event->organizationId, function () use ($event): void {
            $form = Form::query()->find($event->formId);
            $version = FormVersion::query()->find($event->formVersionId);
            if (! $form instanceof Form || ! $version instanceof FormVersion || $form->current_version_id !== $version->id) {
                return;
            }

            $key = $version->version === 1 ? 'form_available' : 'form_updated';
            $this->notifier->send(new NotificationMessage(
                NotificationEvent::FormAvailable,
                "notifications.messages.{$key}.title",
                "notifications.messages.{$key}.body",
                ['form' => $form->name, 'version' => $version->version],
                self::URL,
                $event->organizationId,
            ), $this->recipients($form, $event));
        });
    }

    /** @return Collection<int, User> */
    private function recipients(Form $form, FormPublished $event): Collection
    {
        return User::query()
            ->where('status', UserStatus::Active->value)
            ->when($event->actorId !== null, fn ($query) => $query->whereKeyNot($event->actorId))
            ->whereHas('memberships', fn ($membership) => $membership
                ->where('organization_id', $event->organizationId)
                ->where('status', MembershipStatus::Active->value))
            ->orderBy('id')
            ->get()
            ->filter(fn (User $user): bool => $this->access->canFill($user, $form))
            ->values()
            ->toBase();
    }
}
