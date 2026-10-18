<?php

declare(strict_types=1);

use App\Domain\Audit\Models\Activity;
use App\Domain\Documents\Branding\Branding;
use App\Domain\Documents\Models\Design;
use App\Domain\Forms\Models\Form;
use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\LoginLinks\LoginLinkKind;
use App\Domain\Identity\Models\Group;
use App\Domain\Identity\Models\LoginLink;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Mail\CustomerSend\CustomerSendGate;
use App\Domain\Platform\Auth\PlatformSession;
use App\Domain\Platform\PlatformAdmins;
use App\Domain\Submissions\Models\Submission;
use App\Domain\Workflows\Enums\TriggerType;
use App\Domain\Workflows\Models\WebhookEndpoint;
use App\Domain\Workflows\Models\Workflow;
use App\Filament\Admin\Pages\Auth\ResetPassword;
use Database\Seeders\DemoSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Documents\DocumentFixtures;

it('installs the first organization and owner', function (): void {
    $this->artisan('app:install', [
        '--organization' => 'Müller GmbH', '--name' => 'Max Müller',
        '--email' => 'Max@Example.com', '--password' => 'long-enough-pw', '--locale' => 'de',
    ])->assertSuccessful();

    $org = Organization::query()->sole();
    $owner = User::query()->where('email', 'max@example.com')->sole();
    expect($org->name)->toBe('Müller GmbH')
        ->and($owner->hasVerifiedEmail())->toBeTrue()
        ->and($owner->hasPermissionIn($org, Permission::OrganizationDelete))->toBeTrue();
});

it('is idempotent: a second run changes nothing', function (): void {
    $args = ['--organization' => 'A', '--name' => 'B', '--email' => 'a@example.com', '--password' => 'long-enough-pw'];
    $this->artisan('app:install', $args)->assertSuccessful();
    $this->artisan('app:install', [...$args, '--organization' => 'Second'])->assertSuccessful();

    expect(Organization::query()->pluck('name')->all())->toBe(['A']);
});

it('rejects weak input without creating anything', function (): void {
    $this->artisan('app:install', [
        '--organization' => 'X', '--name' => 'Y', '--email' => 'not-an-email', '--password' => 'short',
    ])->assertFailed();

    expect(Organization::query()->count())->toBe(0);
});

it('seeds one demo user per persona, idempotently', function (): void {
    DocumentFixtures::fakeDisk();
    $this->seed(DemoSeeder::class);
    $this->seed(DemoSeeder::class);

    $org = Organization::query()->where('slug', 'demo')->sole();
    $coordinator = User::query()->where('email', 'coordinator@demo.test')->sole();
    expect($org->members()->count())->toBe(9)
        ->and($coordinator->hasPermissionIn($org, Permission::FormsManage))->toBeTrue()
        ->and($coordinator->hasPermissionIn($org, Permission::SubmissionsReview))->toBeTrue();
});

it('seeds demo branding, the default design and a sendable service report', function (): void {
    DocumentFixtures::fakeDisk();
    $this->seed(DemoSeeder::class);

    $org = Organization::query()->where('slug', 'demo')->sole();
    $branding = Branding::forOrganization($org);
    [$designs, $form] = app(TenantContext::class)->run($org, fn (): array => [
        Design::query()->where('is_default', true)->with('currentVersion')->get(),
        Form::query()->where('key', 'service_report')->with('currentVersion')->sole(),
    ]);
    $probe = new Submission(['form_version_id' => $form->current_version_id]);
    $probe->setRelation('formVersion', $form->currentVersion);

    expect($branding->primaryColor)->toBe('#0f766e')
        ->and($branding->companyName)->toBe('Demo Haustechnik GmbH')
        ->and($branding->company['iban'])->toBe('DE02120300000000202051')
        ->and($branding->logoPath)->not->toBeNull()
        ->and(Storage::disk(DocumentFixtures::DISK)->exists((string) $branding->logoPath))->toBeTrue()
        ->and($designs)->toHaveCount(1)
        ->and($designs->sole()->currentVersion)->not->toBeNull()
        ->and($form->currentVersion?->version)->toBe(1)
        ->and(CustomerSendGate::enabled($probe))->toBeTrue();
});

it('seeds the review-then-send workflow and a disabled webhook example', function (): void {
    DocumentFixtures::fakeDisk();
    $this->seed(DemoSeeder::class);
    $this->seed(DemoSeeder::class); // idempotent

    $org = Organization::query()->where('slug', 'demo')->sole();
    [$workflows, $endpoints, $office, $form] = app(TenantContext::class)->run($org, fn (): array => [
        Workflow::query()->get(),
        WebhookEndpoint::query()->get(),
        Group::query()->where('name', 'Büro')->sole(),
        Form::query()->where('key', 'service_report')->sole(),
    ]);

    expect($workflows)->toHaveCount(1)
        ->and($workflows->sole()->active)->toBeTrue()
        ->and($workflows->sole()->trigger_type)->toBe(TriggerType::SubmissionSubmitted)
        ->and($workflows->sole()->trigger_config)->toBe(['form_ids' => [$form->id]])
        ->and(array_column($workflows->sole()->steps, 'type'))->toBe(['request_review', 'send_email'])
        ->and($workflows->sole()->steps[0]['data']['assignee_group_id'])->toBe($office->id)
        ->and($workflows->sole()->steps[1]['data']['to_fields'])->toBe(['customer.email'])
        ->and($endpoints)->toHaveCount(1)
        ->and($endpoints->sole()->active)->toBeFalse();
});

/** Runs app:install --json and returns its single JSON document (and the raw output). */
function installJson(array $options): array
{
    $exit = Artisan::call('app:install', [...$options, '--json' => true]);
    $output = Artisan::output();
    $lines = array_values(array_filter(explode("\n", trim($output))));

    expect($lines)->toHaveCount(1);

    return [json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR), $output, $exit];
}

function installPasswordFile(string $password): string
{
    $file = (string) tempnam(sys_get_temp_dir(), 'fsa-pw-');
    file_put_contents($file, $password."\n");

    return $file;
}

const INSTALL_OWNER = ['--organization' => 'Acme GmbH', '--name' => 'Olga Owner', '--email' => 'olga@example.com'];

it('reads the owner password from --password-file and makes the owner a platform operator', function (): void {
    $file = installPasswordFile('file-based-pass-123');

    $this->artisan('app:install', [...INSTALL_OWNER, '--password-file' => $file])
        ->doesntExpectOutputToContain('deprecated')->assertSuccessful();

    $owner = User::query()->where('email', 'olga@example.com')->sole();
    expect(Hash::check('file-based-pass-123', $owner->password))->toBeTrue()
        ->and(app(PlatformAdmins::class)->check($owner))->toBeTrue()
        ->and(Activity::query()->where('event', 'platform.admin_granted')->count())->toBe(1);
});

it('still accepts --password but warns that it is deprecated', function (): void {
    $this->artisan('app:install', [...INSTALL_OWNER, '--password' => 'long-enough-pw'])
        ->expectsOutputToContain('The --password option is deprecated')->assertSuccessful();

    expect(Hash::check('long-enough-pw', User::query()->where('email', 'olga@example.com')->sole()->password))->toBeTrue();
});

it('prints one JSON document that never contains the password', function (string $source): void {
    $options = $source === 'file' ? ['--password-file' => installPasswordFile('json-secret-pass-123')] : ['--password' => 'json-secret-pass-123'];

    [$json, $output, $exit] = installJson([...INSTALL_OWNER, ...$options]);

    expect($exit)->toBe(0)
        ->and($output)->not->toContain('json-secret-pass-123')
        ->and($json)->toMatchArray(['schema_version' => 1, 'command' => 'app:install', 'status' => 'ok', 'changed' => true, 'secrets' => [], 'errors' => []])
        ->and($json['data']['organization']['name'])->toBe('Acme GmbH')
        ->and($json['data']['owner'])->toMatchArray(['email' => 'olga@example.com', 'operator' => true, 'password_set' => true])
        ->and(array_column($json['warnings'], 'code'))->toBe($source === 'file' ? [] : ['password_option_deprecated']);
})->with(['file', 'flag']);

it('creates a passwordless owner and returns a one-time first sign-in link only under secrets', function (): void {
    $this->freezeTime();
    clearAuditLog();

    [$json] = installJson([...INSTALL_OWNER, '--owner-link' => true, '--actor-uid' => '1000']);

    $owner = User::query()->where('email', 'olga@example.com')->sole();
    $link = $json['secrets'][0];
    $token = Str::after($link['value'], '#');
    $issued = Activity::query()->where('event', 'login_link.issued')->sole();
    expect($json['secrets'])->toHaveCount(1)
        ->and($link['name'])->toBe('sign_in_link')
        ->and($link['value'])->toBe(url('/login-link').'#'.$token)
        ->and($link['expires_at'])->toBe(now()->addMinutes(60)->utc()->toIso8601ZuluString())
        ->and(json_encode($json['data']))->not->toContain($token)
        ->and($json['data']['owner']['password_set'])->toBeFalse()
        ->and($owner->password_known)->toBeFalse()
        ->and(app(PlatformAdmins::class)->check($owner))->toBeTrue()
        ->and(LoginLink::query()->sole()->kind)->toBe(LoginLinkKind::Owner)
        ->and(DB::table('password_reset_tokens')->where('email', 'olga@example.com')->exists())->toBeTrue()
        ->and($issued->actor_type)->toBe('cli')
        ->and($issued->properties['host_uid'])->toBe(1000);
});

it('leads the owner link to setting a password, signs the owner in, offers MFA and shows "used" afterwards', function (): void {
    Filament::setCurrentPanel('admin');
    [$json] = installJson([...INSTALL_OWNER, '--owner-link' => true]);
    $token = Str::after($json['secrets'][0]['value'], '#');
    $owner = User::query()->where('email', 'olga@example.com')->sole();

    $response = $this->post('/login-link', ['token' => $token])->assertStatus(303);
    $location = (string) $response->headers->get('Location');
    expect($location)->toContain('/admin/password-reset/reset')->toContain('signature=')->not->toContain($token);
    $this->assertGuest();

    Livewire::test(ResetPassword::class, ['email' => 'olga@example.com'])
        ->assertSet('fromOwnerLink', true)
        ->fillForm(['password' => 'N3w-Owner-Passw0rd!', 'passwordConfirmation' => 'N3w-Owner-Passw0rd!'])
        ->call('resetPassword')
        ->assertHasNoErrors()
        ->assertNotified(__('login_link.mfa.offer_title'))
        ->assertRedirect((string) Filament::getProfileUrl());

    $this->assertAuthenticatedAs($owner);
    expect(Hash::check('N3w-Owner-Passw0rd!', $owner->fresh()?->password))->toBeTrue()
        ->and($owner->fresh()?->password_known)->toBeTrue()
        ->and(DB::table('password_reset_tokens')->count())->toBe(0)
        ->and(app(PlatformSession::class)->isValid($owner))->toBeFalse();

    auth()->logout();
    $this->post('/login-link', ['token' => $token])->assertStatus(410)
        ->assertSee(__('login_link.states.used.heading'))->assertSee('fsa login-link');
});

it('opens the signed reset page without a token in its URL', function (): void {
    [$json] = installJson([...INSTALL_OWNER, '--owner-link' => true]);
    $location = (string) $this->post('/login-link', ['token' => Str::after($json['secrets'][0]['value'], '#')])->headers->get('Location');

    expect(parse_url($location, PHP_URL_QUERY))->not->toContain('token=');
    $this->get($location)->assertOk()->assertSee('olga@example.com');
});

it('refuses missing or conflicting password sources without creating anything', function (array $options, string $message): void {
    [$json, , $exit] = installJson([...INSTALL_OWNER, ...$options]);

    expect($exit)->toBe(1)
        ->and($json['status'])->toBe('failed')
        ->and($json['errors'][0]['code'])->toBe('invalid_input')
        ->and($json['errors'][0]['message'])->toContain($message)
        ->and(Organization::query()->count())->toBe(0);
})->with([
    'no password' => [[], '--password-file or --owner-link'],
    'two sources' => [['--owner-link' => true, '--password' => 'long-enough-pw'], 'only one of'],
    'unreadable file' => [['--password-file' => '/nonexistent/pw'], 'cannot be read'],
    'bad actor uid' => [['--owner-link' => true, '--actor-uid' => 'root'], '--actor-uid'],
]);

it('reports an existing installation in JSON as unchanged', function (): void {
    installJson([...INSTALL_OWNER, '--owner-link' => true]);

    [$json, , $exit] = installJson([...INSTALL_OWNER, '--owner-link' => true]);

    expect($exit)->toBe(0)
        ->and($json['changed'])->toBeFalse()
        ->and($json['secrets'])->toBe([])
        ->and($json['warnings'][0]['code'])->toBe('already_installed')
        ->and(LoginLink::query()->count())->toBe(1);
});
