<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Privacy\Erasure\AccountScrubber;
use App\Filament\Admin\Resources\Members\Pages\ManageMembers;
use Livewire\Livewire;

/* ADR 0039: erased accounts keep a neutral marker name; screens show it in the viewer's language. */

it('shows an erased member as "Gelöschte Person" in German and keeps other names', function (): void {
    $org = makeOrganization();
    $owner = ownerOf($org);
    $erased = memberOf($org, SystemRole::FieldWorker);
    $erased->forceFill(['name' => AccountScrubber::ERASED_NAME])->save();
    actingInAdmin($owner, $org);
    app()->setLocale('de');

    Livewire::test(ManageMembers::class)
        ->assertSee('Gelöschte Person')
        ->assertDontSee(AccountScrubber::ERASED_NAME)
        ->assertSee($owner->name);

    expect(AccountScrubber::displayName('Jane Doe'))->toBe('Jane Doe');
});
