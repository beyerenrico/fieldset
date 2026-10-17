<?php

declare(strict_types=1);

use App\Domain\Identity\Actions\AddMember;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Tokens\TokenAbility as A;
use App\Domain\Platform\Impersonation\Impersonation;
use App\Domain\Platform\Impersonation\ImpersonationStore;
use App\Filament\Admin\Pages\ApiTokens\ApiTokens;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Livewire;
use Tests\Feature\ApiTokens\Support\TokenApi;

beforeEach(function (): void {
    $this->org = makeOrganization();
    $this->owner = ownerOf($this->org);
    $this->designer = memberOf($this->org, SystemRole::Designer);
});

function tokenOf(string $plain): PersonalAccessToken
{
    $token = PersonalAccessToken::findToken($plain);
    expect($token)->toBeInstanceOf(PersonalAccessToken::class);

    return $token;
}

it('creates a token and shows the plain text once', function (): void {
    actingInAdmin($this->designer, $this->org);

    $page = Livewire::test(ApiTokens::class)->assertOk()
        ->callAction('create', ['name' => 'ERP', 'abilities' => ['forms:read', 'submissions:read'], 'expires_in' => '30'])
        ->assertHasNoActionErrors()
        ->assertActionMounted('showToken');

    $token = PersonalAccessToken::query()->sole();
    $plain = (string) data_get($page->instance()->mountedActions, '0.data.token');
    expect(tokenOf($plain)->is($token))->toBeTrue()
        ->and($token->getAttribute('organization_id'))->toBe($this->org->id)
        ->and($token->abilities)->toBe(['forms:read', 'submissions:read'])
        ->and($token->expires_at?->isSameDay(now()->addDays(30)))->toBeTrue();

    $page->callMountedAction()->assertActionNotMounted('showToken')
        ->assertDontSee($plain)->assertSee('ERP');
    Livewire::test(ApiTokens::class)->assertDontSee(explode('|', $plain)[1])->assertCanSeeTableRecords([$token]);
});

it('validates the create form', function (): void {
    actingInAdmin($this->owner, $this->org);

    Livewire::test(ApiTokens::class)
        ->callAction('create', ['name' => '', 'abilities' => [], 'expires_in' => '90'])
        ->assertHasActionErrors(['name' => 'required', 'abilities' => 'required']);
    expect(PersonalAccessToken::query()->count())->toBe(0);
});

it('creates tokens without expiry and defaults to the configured expiry', function (): void {
    actingInAdmin($this->owner, $this->org);

    Livewire::test(ApiTokens::class)
        ->mountAction('create')->assertActionDataSet(['expires_in' => '90'])
        ->setActionData(['name' => 'BI', 'abilities' => ['forms:read'], 'expires_in' => 'never'])
        ->callMountedAction()->assertHasNoActionErrors();

    expect(PersonalAccessToken::query()->sole()->expires_at)->toBeNull();
});

it('lists own tokens with abilities, last use and expiry', function (): void {
    $mine = tokenOf(TokenApi::issue($this->designer, $this->org, [A::FormsRead, A::TasksRead], now()->addDays(10), 'Reporting'));
    $owners = tokenOf(TokenApi::issue($this->owner, $this->org, [A::FormsRead], name: 'Owner token'));
    $foreign = tokenOf(TokenApi::issue(ownerOf($other = makeOrganization(name: 'Fremd GmbH')), $other, [A::FormsRead], name: 'Foreign'));
    actingInAdmin($this->designer, $this->org);

    Livewire::test(ApiTokens::class)
        ->assertCanSeeTableRecords([$mine])->assertCanNotSeeTableRecords([$owners, $foreign])
        ->assertSee('Reporting')->assertSee(A::FormsRead->label())->assertSee(A::TasksRead->label())
        ->assertSee(__('api_tokens.table.never_used'))
        ->assertActionVisible(TestAction::make('revoke')->table($mine));
});

it('shows Owners and Admins every token of the organization and lets them revoke it', function (): void {
    $designerToken = tokenOf(TokenApi::issue($this->designer, $this->org, [A::FormsRead], name: 'Designer token'));
    $foreign = tokenOf(TokenApi::issue(ownerOf($other = makeOrganization(name: 'Fremd GmbH')), $other, [A::FormsRead]));
    actingInAdmin($this->owner, $this->org);

    Livewire::test(ApiTokens::class)
        ->assertCanSeeTableRecords([$designerToken])->assertCanNotSeeTableRecords([$foreign])
        ->assertSee($this->designer->name)
        ->callAction(TestAction::make('revoke')->table($designerToken));

    expect(PersonalAccessToken::query()->whereKey($designerToken->id)->exists())->toBeFalse()
        ->and(PersonalAccessToken::query()->whereKey($foreign->id)->exists())->toBeTrue();
});

it('lets users revoke their own token', function (): void {
    $mine = tokenOf(TokenApi::issue($this->designer, $this->org, [A::FormsRead]));
    actingInAdmin($this->designer, $this->org);

    Livewire::test(ApiTokens::class)->callAction(TestAction::make('revoke')->table($mine));

    expect(PersonalAccessToken::query()->count())->toBe(0);
});

it('is served in the Team navigation of the panel', function (): void {
    actingInAdmin($this->designer, $this->org);

    $this->get(ApiTokens::getUrl())->assertOk()->assertSee(__('api_tokens.title'));
    expect(ApiTokens::getNavigationGroup())->toBe(__('identity.navigation.team'));
});

it('is closed to members without panel access', function (): void {
    $worker = memberOf($this->org, SystemRole::FieldWorker);
    app(AddMember::class)->handle(makeOrganization(name: 'Panel GmbH'), $worker, [SystemRole::Owner]);   // panel access elsewhere
    actingInAdmin($worker, $this->org);

    expect(ApiTokens::canAccess())->toBeFalse();
});

it('refuses to create tokens while impersonating', function (): void {
    actingInAdmin($this->designer, $this->org);
    app(ImpersonationStore::class)->put(new Impersonation(
        $this->owner->id, $this->designer->id, $this->org->id, 'Support', CarbonImmutable::now(), CarbonImmutable::now()->addMinutes(30),
    ));

    Livewire::test(ApiTokens::class)->assertActionHidden('create');
});
