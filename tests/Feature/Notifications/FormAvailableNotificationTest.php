<?php

declare(strict_types=1);

use App\Domain\Forms\Actions\CreateFormFromTemplate;
use App\Domain\Forms\Actions\PublishForm;
use App\Domain\Forms\Actions\RollbackForm;
use App\Domain\Forms\Actions\SyncFormAccess;
use App\Domain\Forms\Models\Form;
use App\Domain\Identity\Actions\SyncMemberGroups;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\Group;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Notifications\Listeners\NotifyFormAvailable;
use App\Domain\Notifications\NotificationEvent;
use App\Domain\Notifications\Preferences\ChannelPreferences;
use Tests\Feature\Notifications\NotificationFixtures as N;

/* M4-T06: "new form available" reaches the users who may fill the form (§8.9, §8.6), not the publisher. */

beforeEach(function (): void {
    $this->org = makeOrganization();
    $this->owner = ownerOf($this->org);
    $this->designer = memberOf($this->org, SystemRole::Designer);
    $this->granted = memberOf($this->org, SystemRole::FieldWorker);
    app(TenantContext::class)->set($this->org);
    $this->form = app(CreateFormFromTemplate::class)->handle($this->designer, 'service_report');
});

/** Grants the form to the field_worker role and to a new group with the given member. */
function grantFormToGroupOf(Form $form, User $member, User $actor): void
{
    $group = Group::query()->create(['name' => 'Technik']);
    app(SyncMemberGroups::class)->handle(Organization::query()->findOrFail($form->organization_id), $member, [$group->id], $actor);
    app(SyncFormAccess::class)->handle($form, [$group->id], ['field_worker'], $actor);
}

it('notifies everyone who may fill the newly published form, except the publisher', function (): void {
    $ungranted = memberOf($this->org, SystemRole::Reviewer); // forms.fill, but neither role nor group grant
    $viaGroup = memberOf($this->org, SystemRole::Reviewer);
    $outsider = memberOf(makeOrganization(name: 'Other GmbH'), SystemRole::FieldWorker);
    $deactivated = memberOf($this->org, SystemRole::Admin);
    $deactivated->forceFill(['status' => UserStatus::Deactivated, 'deactivated_at' => now()])->save();
    grantFormToGroupOf($this->form, $viaGroup, $this->owner);

    app(PublishForm::class)->handle($this->form, 'v1', $this->designer);

    $notified = fn (User $user): int => N::stored($user)->where('data.event', NotificationEvent::FormAvailable->value)->count();
    expect($notified($this->granted))->toBe(1)      // role grant
        ->and($notified($viaGroup))->toBe(1)        // group grant
        ->and($notified($this->owner))->toBe(1)     // forms.fill_all
        ->and($notified($this->designer))->toBe(0)  // the publisher
        ->and($notified($ungranted))->toBe(0)
        ->and($notified($outsider))->toBe(0)
        ->and($notified($deactivated))->toBe(0);

    $data = N::stored($this->granted)->first()->data;
    expect($data['text']['title'])->toBe('New form: '.$this->form->name)
        ->and($data['url'])->toBe(NotifyFormAvailable::URL)
        ->and($data['organization_id'])->toBe($this->org->id)
        ->and(N::mails())->toBeEmpty(); // email is off by default for this event
});

it('emails users who opted in and announces later versions as updates', function (): void {
    app(SyncFormAccess::class)->handle($this->form, [], ['field_worker'], $this->designer);
    app(ChannelPreferences::class)->update($this->granted, ['form_available' => ['mail' => true]]);
    $this->granted->update(['locale' => 'de']);

    app(PublishForm::class)->handle($this->form, 'v1', $this->designer);
    $v2 = app(PublishForm::class)->handle($this->form->refresh(), 'v2', $this->designer);
    app(RollbackForm::class)->handle($this->form->refresh(), $v2->form->versions()->where('version', 1)->sole(), $this->owner);

    $name = $this->form->name;
    expect(N::stored($this->granted)->pluck('data.text.title')->all())->toBe([
        "Formular aktualisiert: {$name}", "Formular aktualisiert: {$name}", "Neues Formular: {$name}",
    ])
        ->and(N::stored($this->granted)->first()->data['text']['body'])->toBe("Version 3 von „{$name}“ ist in der App verfügbar.")
        ->and(N::mailRecipients())->toBe([$this->granted->email, $this->granted->email, $this->granted->email])
        ->and(N::stored($this->owner)->count())->toBe(2); // not for the rollback the owner published
});

it('notifies nobody when the published form cannot be filled', function (): void {
    app(PublishForm::class)->handle($this->form, 'v1', $this->designer);

    expect(N::stored($this->granted))->toBeEmpty()->and(N::stored($this->owner))->toHaveCount(1);
});
