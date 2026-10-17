<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\SystemRole;

/* The admin user menu links every signed-in member to "My data" (GDPR self-service: export, erasure). */

it('shows "My data" in the user menu of every admin member', function (SystemRole $role, string $locale, string $label): void {
    $org = makeOrganization();
    $user = $role === SystemRole::Owner ? ownerOf($org) : memberOf($org, $role);
    $user->forceFill(['locale' => $locale])->save();

    $this->actingAs($user)->get('/admin/'.$org->slug)->assertOk()
        ->assertSee('href="'.url('/admin/'.$org->slug.'/privacy/my-data').'"', false)
        ->assertSee($label);
})->with([
    'owner, English' => [SystemRole::Owner, 'en', 'My data'],
    'reviewer, German' => [SystemRole::Reviewer, 'de', 'Meine Daten'],
]);
