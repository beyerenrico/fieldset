<?php

declare(strict_types=1);

use App\Domain\Audit\Models\Activity;
use App\Domain\Documents\Designs\Actions\CreateDesign;
use App\Domain\Documents\Designs\Actions\PublishDesign;
use App\Domain\Documents\Designs\Actions\RetireDesignVersion;
use App\Domain\Documents\Designs\Actions\RollbackDesign;
use App\Domain\Documents\Designs\Actions\SaveDesignDraft;
use App\Domain\Documents\Designs\Actions\SetDefaultDesign;
use App\Domain\Documents\Designs\DesignResolver;
use App\Domain\Documents\Models\Design;
use App\Domain\Documents\Models\DesignVersion;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Tenancy\TenantContext;
use FormSchema\Json\Json;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->org = makeOrganization();
    $this->designer = memberOf($this->org, SystemRole::Designer);
    app(TenantContext::class)->set($this->org);
});

it('creates a design with version 1 published; the first design is the default', function (): void {
    $first = app(CreateDesign::class)->handle('Briefkopf Blau', $this->designer);
    $second = app(CreateDesign::class)->handle('Briefkopf Blau', $this->designer);

    expect($first->key)->toBe('briefkopf_blau')->and($second->key)->toBe('briefkopf_blau_2')
        ->and($first->is_default)->toBeTrue()->and($second->is_default)->toBeFalse()
        ->and($first->currentVersion?->version)->toBe(1)
        ->and($first->currentVersion?->checksum)->toBe(Json::checksum($first->settings))
        ->and(Activity::query()->where('event', 'design.created')->count())->toBe(2);
});

it('publishes the draft as version N+1 and leaves older versions untouched', function (): void {
    $design = app(CreateDesign::class)->handle('Standard', $this->designer);
    $v1 = $design->currentVersion;
    app(SaveDesignDraft::class)->handle($design, 'Standard', [...$design->settings, 'paper' => 'letter'], $this->designer);

    $v2 = app(PublishDesign::class)->handle($design->refresh(), 'US paper', $this->designer);

    expect($v2->version)->toBe(2)->and($v2->settings['paper'])->toBe('letter')
        ->and($v1?->refresh()->settings['paper'])->toBe('a4')
        ->and($design->refresh()->current_version_id)->toBe($v2->id)
        ->and(Activity::query()->where('event', 'design.published')->count())->toBe(1)
        ->and(Activity::query()->where('event', 'design.updated')->sole()->properties['changed'])->toBe(['paper']);
});

it('requires a changelog to publish', function (): void {
    $design = app(CreateDesign::class)->handle('Standard', $this->designer);

    expect(fn () => app(PublishDesign::class)->handle($design, '  ', $this->designer))->toThrow(InvalidArgumentException::class);
});

it('keeps design versions immutable in the model and the database; only retiring is allowed', function (): void {
    $version = app(CreateDesign::class)->handle('Standard', $this->designer)->currentVersion;
    assert($version instanceof DesignVersion);

    expect(fn () => $version->forceFill(['changelog' => 'edited'])->save())->toThrow(LogicException::class)
        ->and(fn () => $version->delete())->toThrow(LogicException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('design_versions')->where('id', $version->id)->update(['settings' => '{}'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('design_versions')->where('id', $version->id)->delete()))->toThrow(QueryException::class);

    app(RetireDesignVersion::class)->handle($version, $this->designer);

    expect($version->refresh()->isRetired())->toBeTrue()
        ->and(fn () => DB::transaction(fn () => DB::table('design_versions')->where('id', $version->id)->update(['retired_at' => null])))->toThrow(QueryException::class)
        ->and(Activity::query()->where('event', 'design.version_retired')->count())->toBe(1);
});

it('rolls back by publishing a copy of version K as N+1', function (): void {
    $design = app(CreateDesign::class)->handle('Standard', $this->designer);
    $v1 = $design->currentVersion;
    assert($v1 instanceof DesignVersion);
    app(PublishDesign::class)->handle($design, 'Letter', $this->designer, [...$design->settings, 'paper' => 'letter']);

    $v3 = app(RollbackDesign::class)->handle($design->refresh(), $v1, $this->designer);

    expect($v3->version)->toBe(3)
        ->and($v3->settings)->toEqual($v1->settings)
        ->and($v3->checksum)->toBe($v1->checksum)
        ->and($v3->changelog)->toContain('1')
        ->and($design->refresh()->settings['paper'])->toBe('a4')
        ->and(DesignVersion::query()->where('design_id', $design->id)->count())->toBe(3)
        ->and(Activity::query()->where('event', 'design.rolled_back')->count())->toBe(1);
});

it('moves the single default flag', function (): void {
    $a = app(CreateDesign::class)->handle('A', $this->designer);
    $b = app(CreateDesign::class)->handle('B', $this->designer);

    app(SetDefaultDesign::class)->handle($b, $this->designer);

    expect($a->refresh()->is_default)->toBeFalse()->and($b->refresh()->is_default)->toBeTrue()
        ->and(fn () => DB::transaction(fn () => DB::table('designs')->where('id', $a->id)->update(['is_default' => true])))->toThrow(QueryException::class);
});

it('resolves the design a definition selects, falls back to the default and creates one lazily', function (): void {
    $resolver = app(DesignResolver::class);
    $definition = (object) ['settings' => (object) ['document' => (object) ['design' => 'invoice_layout']]];

    $lazy = $resolver->forDefinition($definition);
    expect(Design::query()->sole()->key)->toBe('default')->and($lazy->version)->toBe(1);

    $named = app(CreateDesign::class)->handle('Invoice layout', $this->designer);
    expect($named->key)->toBe('invoice_layout')
        ->and($resolver->forDefinition($definition)->design_id)->toBe($named->id)
        ->and($resolver->forDefinition((object) [])->design_id)->toBe($lazy->design_id);

    $v2 = app(PublishDesign::class)->handle($named, 'v2', $this->designer);
    app(RetireDesignVersion::class)->handle($v2, $this->designer);
    expect($resolver->forDefinition($definition)->version)->toBe(1);   // newest non-retired version
});

it('resolves the spec default "default" to whichever design is marked default', function (): void {
    $resolver = app(DesignResolver::class);
    $lazy = $resolver->forDefinition((object) ['settings' => (object) ['document' => (object) ['design' => 'default']]]);
    $other = app(CreateDesign::class)->handle('Neu', $this->designer);

    app(SetDefaultDesign::class)->handle($other, $this->designer);

    expect($lazy->design->key)->toBe('default')
        ->and($resolver->forDefinition((object) ['settings' => (object) ['document' => (object) ['design' => 'default']]])->design_id)->toBe($other->id)
        ->and($resolver->forDefinition((object) [])->design_id)->toBe($other->id);
});

it('isolates designs per organization', function (): void {
    $other = makeOrganization(name: 'Other');
    app(TenantContext::class)->run($other, fn () => app(CreateDesign::class)->handle('Fremd', null));

    expect(Design::query()->count())->toBe(0)
        ->and(app(DesignResolver::class)->defaultDesign()->organization_id)->toBe($this->org->id);
});
