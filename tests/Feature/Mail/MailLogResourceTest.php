<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Mail\Enums\MailStatus;
use App\Domain\Mail\Jobs\DeliverMail;
use App\Domain\Mail\Models\MailMessage;
use App\Filament\Admin\Resources\MailMessages\MailMessageResource;
use App\Filament\Admin\Resources\MailMessages\Pages\ListMailMessages;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->org = makeOrganization();
    $this->owner = ownerOf($this->org);
    actingInAdmin($this->owner, $this->org);
});

it('lists only the organization\'s mail, filters by status and is read-only', function (): void {
    $sent = MailMessage::factory()->create(['status' => MailStatus::Sent, 'subject' => 'Delivery note LS-1']);
    $failed = MailMessage::factory()->failed()->create(['subject' => 'Delivery note LS-2']);
    $other = makeOrganization(name: 'Other');
    $foreign = app(TenantContext::class)->run($other, fn () => MailMessage::factory()->create());
    app(TenantContext::class)->set($this->org);

    Livewire::test(ListMailMessages::class)
        ->assertCanSeeTableRecords([$sent, $failed])
        ->assertCanNotSeeTableRecords([$foreign])
        ->filterTable('status', 'failed')
        ->assertCanSeeTableRecords([$failed])
        ->assertCanNotSeeTableRecords([$sent]);

    expect(MailMessageResource::canCreate())->toBeFalse()
        ->and($this->owner->can('update', $sent))->toBeFalse()
        ->and($this->owner->can('delete', $sent))->toBeFalse()
        ->and($this->owner->can('view', $foreign))->toBeFalse();
});

it('retries failed mail from the log; the action is hidden for other states', function (): void {
    Queue::fake();
    $failed = MailMessage::factory()->failed('Connection refused')->create();
    $sent = MailMessage::factory()->create(['status' => MailStatus::Sent]);

    Livewire::test(ListMailMessages::class)
        ->assertTableActionHidden('retry', $sent)
        ->assertTableActionVisible('retry', $failed)
        ->callTableAction('retry', $failed)
        ->assertNotified();

    expect($failed->fresh())->status->toBe(MailStatus::Queued)->error->toBeNull();
    Queue::assertPushed(DeliverMail::class, fn (DeliverMail $job): bool => $job->mailMessageId === $failed->id);
});

it('shows the error text of a failed message', function (): void {
    $failed = MailMessage::factory()->failed('Expected response code 250 but got 550')->create();

    Livewire::test(ListMailMessages::class)
        ->mountTableAction('view', $failed)
        ->assertMountedActionModalSee('Expected response code 250 but got 550');
});

it('is hidden from members without the organization-settings capability', function (): void {
    actingInAdmin(memberOf($this->org, SystemRole::Reviewer), $this->org);

    $this->get(MailMessageResource::getUrl('index'))->assertForbidden();
});
