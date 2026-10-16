<?php

declare(strict_types=1);

use App\Domain\Identity\Actions\AddMember;
use App\Domain\Identity\Actions\SetMembershipStatus;
use App\Domain\Identity\Enums\MembershipStatus;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Membership;
use App\Domain\Notifications\NotificationEvent;
use App\Domain\Notifications\Notifier;
use App\Filament\Admin\Livewire\DatabaseNotifications;
use Filament\Facades\Filament;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\Feature\Notifications\NotificationFixtures as N;

/* M4 review fix S2: the inbox (API and admin bell) only shows notifications of organizations the user still belongs to. */

beforeEach(function (): void {
    $this->org = makeOrganization();
    $this->former = makeOrganization(name: 'Former GmbH');
    $this->user = memberOf($this->org, SystemRole::Reviewer);
    app(AddMember::class)->handle($this->former, $this->user, [SystemRole::Reviewer]);
    foreach ([[$this->org, 'Current'], [$this->former, 'Former'], [null, 'Platform']] as [$org, $form]) {
        app(Notifier::class)->send(N::message(NotificationEvent::FormAvailable, $org, ['form' => $form]), [$this->user], [Notifier::CHANNEL_DATABASE]);
    }
    $membership = Membership::query()->where('organization_id', $this->former->id)->where('user_id', $this->user->id)->sole();
    app(SetMembershipStatus::class)->handle($membership, MembershipStatus::Deactivated, ownerOf($this->former));
});

it('hides notifications of organizations whose membership ended from the API inbox', function (): void {
    Sanctum::actingAs($this->user);

    $response = $this->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('meta.unread', 2);
    expect($response->json('data.*.title'))->toEqualCanonicalizing(['New form: Current', 'New form: Platform']);

    $this->postJson('/api/v1/notifications/read')->assertOk()->assertJsonPath('data.unread', 0);
    expect(N::stored($this->user)->whereNull('read_at')->count())->toBe(1);   // the hidden one is untouched
});

it('shows them again after the membership is reactivated', function (): void {
    $membership = Membership::query()->where('organization_id', $this->former->id)->where('user_id', $this->user->id)->sole();
    app(SetMembershipStatus::class)->handle($membership, MembershipStatus::Active, ownerOf($this->former));
    Sanctum::actingAs($this->user);

    $this->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('meta.total', 3);
});

it('applies the same filter to the admin bell', function (): void {
    actingInAdmin($this->user, $this->org);

    expect(Filament::getPanel('admin')->getDatabaseNotificationsLivewireComponent())->toBe(DatabaseNotifications::class);
    Livewire::test(DatabaseNotifications::class)->assertOk()
        ->assertSee('New form: Current')->assertSee('New form: Platform')
        ->assertDontSee('New form: Former');
});
