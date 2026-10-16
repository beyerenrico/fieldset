<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Notifications\NotificationEvent;
use App\Domain\Notifications\Notifier;
use App\Domain\Notifications\Preferences\ChannelPreferences;
use App\Filament\Admin\Pages\NotificationPreferences;
use Filament\Facades\Filament;
use Filament\Notifications\Livewire\DatabaseNotifications;
use Filament\Pages\Dashboard;
use Livewire\Livewire;
use Tests\Feature\Notifications\NotificationFixtures as N;

/* M4-T06: admin bell (Filament database notifications, polling) and the preferences page in the user menu. */

beforeEach(function (): void {
    $this->org = makeOrganization();
    $this->reviewer = memberOf($this->org, SystemRole::Reviewer);
});

it('shows Notifier notifications in the admin bell, polling without Reverb', function (): void {
    app(Notifier::class)->send(
        N::message(NotificationEvent::ReviewAssigned, $this->org, ['form' => 'Wartung <Nord>'], '/admin/tasks'),
        [$this->reviewer],
        [Notifier::CHANNEL_DATABASE],
    );
    actingInAdmin($this->reviewer, $this->org);

    Livewire::test(DatabaseNotifications::class)
        ->assertOk()
        ->assertSee('New form: Wartung &lt;Nord&gt;', false)
        ->assertDontSee('Wartung <Nord>', false)
        ->assertSee('/admin/tasks')
        ->call('markAllNotificationsAsRead');

    expect(N::stored($this->reviewer)->first()->read_at)->not->toBeNull()
        ->and(Filament::getPanel('admin')->getDatabaseNotificationsPollingInterval())->toBe('30s');
});

it('links the preferences page from the user menu', function (): void {
    actingInAdmin($this->reviewer, $this->org);

    $this->get(Dashboard::getUrl())->assertOk()
        ->assertSee(NotificationPreferences::getUrl())
        ->assertSee(__('notifications.preferences.title'));
});

it('shows a checkbox per event and channel with the current settings', function (): void {
    app(ChannelPreferences::class)->update($this->reviewer, ['form_available' => ['mail' => true]]);
    actingInAdmin($this->reviewer, $this->org);

    $this->get(NotificationPreferences::getUrl())->assertOk()
        ->assertSee(__('notifications.events.review_assigned'))
        ->assertSee(__('notifications.channels.mail'));

    Livewire::test(NotificationPreferences::class)
        ->assertFormSet([
            'review_assigned.database' => true, 'review_assigned.mail' => true,
            'form_available.database' => true, 'form_available.mail' => true,
            'workflow_notice.mail' => false,
        ]);
});

it('saves the settings for the signed-in user only', function (): void {
    $other = memberOf($this->org, SystemRole::Reviewer);
    actingInAdmin($this->reviewer, $this->org);

    Livewire::test(NotificationPreferences::class)
        ->fillForm(['review_assigned.mail' => false, 'workflow_notice.mail' => true, 'form_available.database' => false])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified(__('notifications.preferences.saved'));

    expect(app(ChannelPreferences::class)->matrix($this->reviewer))
        ->review_assigned->toBe(['database' => true, 'mail' => false])
        ->workflow_notice->toBe(['database' => true, 'mail' => true])
        ->form_available->toBe(['database' => false, 'mail' => false])
        ->and(app(ChannelPreferences::class)->matrix($other)['review_assigned'])->toBe(['database' => true, 'mail' => true]);
});

it('renders the page in German', function (): void {
    $this->reviewer->update(['locale' => 'de']);
    actingInAdmin($this->reviewer, $this->org);

    $this->get(NotificationPreferences::getUrl())->assertOk()
        ->assertSee('Benachrichtigungen')
        ->assertSee('Mir wurde eine Prüfung zugewiesen')
        ->assertSee('In der App');
});
