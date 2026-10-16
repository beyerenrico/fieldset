<?php

declare(strict_types=1);

use App\Domain\Identity\Actions\DeactivateUser;
use App\Domain\Identity\Devices\RevokeDevice;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Device;
use App\Http\Middleware\EndRevokedDeviceSession;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\FieldAppScenario;

beforeEach(function (): void {
    Storage::fake('local');
    $this->s = FieldAppScenario::make();
    $this->headers = apiHeaders($this->s->worker, $this->s->org);
    $this->uuid = FieldAppScenario::uuid();
    $this->withHeaders($this->headers)->putJson("/api/v1/submissions/{$this->uuid}", ['form_version_id' => $this->s->version->id, 'data' => ['customer_name' => 'A']])->assertCreated();
});

function upload(string $name, string $bytes, string $kind, string $element, ?string $uuid = null): TestResponse
{
    return test()->withHeaders(test()->headers)->post('/api/v1/submissions/'.test()->uuid.'/attachments', [
        'file' => UploadedFile::fake()->createWithContent($name, $bytes),
        'attachment_uuid' => $uuid ?? FieldAppScenario::uuid(), 'kind' => $kind, 'element_key' => $element,
    ]);
}

function jpegWithExif(): string
{
    $image = imagecreatetruecolor(8, 8);
    ob_start();
    imagejpeg($image);
    $jpeg = (string) ob_get_clean();
    $exif = "Exif\0\0".str_repeat('GPS-SECRET', 4);
    $app1 = "\xFF\xE1".pack('n', strlen($exif) + 2).$exif;

    return substr($jpeg, 0, 2).$app1.substr($jpeg, 2);
}

it('stores photos re-encoded without EXIF and is idempotent by attachment uuid', function (): void {
    $id = FieldAppScenario::uuid();
    $first = upload('photo.jpg', jpegWithExif(), 'photo', 'photos', $id)->assertCreated();
    upload('photo.jpg', jpegWithExif(), 'photo', 'photos', $id)->assertOk()->assertJsonPath('data.attachment.uuid', $id);

    $stored = Storage::disk('local')->get("attachments/{$this->s->org->id}/{$this->uuid}/{$id}.jpg");
    expect($stored)->not->toContain('GPS-SECRET')
        ->and($first->json('data.attachment.sha256'))->toBe(hash('sha256', (string) $stored))
        ->and($first->json('data.attachment.mime'))->toBe('image/jpeg');
});

it('sniffs content instead of trusting the name and enforces the kind allow-list', function (): void {
    upload('evil.jpg', '<?php echo "x";', 'photo', 'photos')->assertUnprocessable()->assertJsonPath('error.code', 'file_type_not_allowed');
    upload('doc.pdf', "%PDF-1.4\n%%EOF", 'signature', 'customer_signature')->assertUnprocessable()->assertJsonPath('error.code', 'file_type_not_allowed');
    upload('p.png', FieldAppScenario::png(), 'photo', 'customer_signature')->assertUnprocessable()->assertJsonFragment(['code' => 'invalid_kind']);
    upload('p.png', FieldAppScenario::png(), 'photo', 'nope')->assertUnprocessable()->assertJsonFragment(['code' => 'unknown_element']);
});

it('enforces the element size limit', function (): void {
    $big = imagecreatetruecolor(2000, 2000);
    for ($i = 0; $i < 4000; $i++) {
        imagesetpixel($big, random_int(0, 1999), random_int(0, 1999), random_int(0, 0xFFFFFF));
    }
    ob_start();
    imagepng($big, null, 0);
    $png = (string) ob_get_clean();
    $this->s->version->definition; // photos maxSizeKb is 8192 in the template; signature has no limit → global 25 MB

    upload('huge.png', str_repeat($png, 3), 'photo', 'photos')->assertUnprocessable()->assertJsonPath('error.code', 'file_too_large');
});

it('serves attachments only to users who can see the submission', function (): void {
    $id = FieldAppScenario::uuid();
    upload('s.png', FieldAppScenario::png(), 'signature', 'customer_signature', $id)->assertCreated();

    $this->withHeaders($this->headers)->get("/api/v1/submissions/{$this->uuid}/attachments/{$id}")
        ->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');

    $peer = memberOf($this->s->org, SystemRole::FieldWorker);
    $this->withHeaders(apiHeaders($peer, $this->s->org))->getJson("/api/v1/submissions/{$this->uuid}/attachments/{$id}")->assertNotFound();
});

it('registers devices and answers device-status with ok, reauth and wipe', function (): void {
    $deviceUuid = (string) Str::uuid();
    $secret = $this->withHeaders($this->headers)->postJson('/api/v1/devices', ['device_uuid' => $deviceUuid, 'name' => 'Pixel', 'platform' => 'android'])
        ->assertCreated()->json('data.secret');
    expect(Device::query()->sole()->secret_hash)->not->toBe($secret);

    $status = fn () => $this->withHeaders(['X-Device-Id' => $deviceUuid, 'X-Device-Secret' => $secret])->postJson('/api/v1/auth/device-status');
    $status()->assertOk()->assertJsonPath('data.status', 'ok');

    app('auth')->forgetGuards();
    $this->app['auth']->guard('sanctum')->forgetUser();
    $this->flushHeaders();
    $this->withHeaders(['X-Device-Id' => $deviceUuid, 'X-Device-Secret' => $secret])->postJson('/api/v1/auth/device-status')
        ->assertOk()->assertJsonPath('data.status', 'reauth');

    app(RevokeDevice::class)->handle(Device::query()->sole(), $this->s->owner);
    $this->withHeaders(['X-Device-Id' => $deviceUuid, 'X-Device-Secret' => $secret])->postJson('/api/v1/auth/device-status')
        ->assertOk()->assertJsonPath('data.status', 'wipe');

    $this->withHeaders(['X-Device-Id' => $deviceUuid, 'X-Device-Secret' => 'wrong'])->postJson('/api/v1/auth/device-status')
        ->assertUnauthorized()->assertJsonPath('error.code', 'device_unknown');
});

it('ends the session bound to a device on its next request once the device is revoked', function (): void {
    $this->flushHeaders();
    app('auth')->forgetGuards();
    $this->actingAs($this->s->worker, 'web');
    $stateful = ['Referer' => 'http://localhost/app/', 'X-Organization-Id' => (string) $this->s->org->id, 'Accept' => 'application/json'];
    $deviceUuid = (string) Str::uuid();

    $this->withHeaders($stateful)->postJson('/api/v1/devices', ['device_uuid' => $deviceUuid, 'name' => 'Pixel'])->assertCreated();
    $device = Device::query()->where('device_uuid', $deviceUuid)->sole();
    expect(session(EndRevokedDeviceSession::SESSION_KEY))->toBe($device->id);
    $this->withHeaders($stateful)->getJson('/api/v1/me')->assertOk();

    app(RevokeDevice::class)->handle($device, $this->s->owner);
    $this->withHeaders($stateful)->getJson('/api/v1/me')->assertUnauthorized()->assertJsonPath('error.code', 'device_revoked');
    expect(auth('web')->check())->toBeFalse()->and(session()->has(EndRevokedDeviceSession::SESSION_KEY))->toBeFalse();
});

it('ends a web session bound to a revoked device with a redirect to the login', function (): void {
    $this->flushHeaders();
    app('auth')->forgetGuards();
    $device = Device::query()->create(['user_id' => $this->s->owner->id, 'device_uuid' => (string) Str::uuid(), 'secret_hash' => 'x']);
    $this->actingAs($this->s->owner, 'web')->withSession([EndRevokedDeviceSession::SESSION_KEY => $device->id]);
    $this->get('/admin')->assertRedirect();
    expect(auth('web')->check())->toBeTrue();

    app(RevokeDevice::class)->handle($device, null);
    $this->get('/admin')->assertRedirect('/admin/login');
    expect(auth('web')->check())->toBeFalse();
});

it('deactivating a user revokes tokens, sessions and requests a wipe on every device', function (): void {
    $device = Device::query()->create(['user_id' => $this->s->worker->id, 'device_uuid' => (string) Str::uuid(), 'secret_hash' => 'x']);
    $this->s->worker->createToken('t');
    DB::table('sessions')->insert(['id' => 'sess-1', 'user_id' => $this->s->worker->id, 'payload' => '', 'last_activity' => time()]);

    app(DeactivateUser::class)->handle($this->s->worker, null);

    expect($device->refresh()->wipe_requested_at)->not->toBeNull()
        ->and($this->s->worker->tokens()->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $this->s->worker->id)->count())->toBe(0)
        ->and($device->mustWipe())->toBeTrue();
});
