<?php

declare(strict_types=1);

use App\Domain\Instance\Release\ReleaseChannel;
use App\Domain\Instance\Release\ReleaseException;
use App\Domain\Instance\Release\ReleaseVersion;

/*
 * Release versions and image tags (BUILD_PROMPT_V2 §6.5, KTD22, plan U7): a stable release moves X.Y, X and
 * latest; a pre-release publishes only its own version.
 */

it('derives the channel and image tags of a stable release', function (): void {
    $version = ReleaseVersion::parse('2.1.3');

    expect($version->channel())->toBe(ReleaseChannel::Stable)
        ->and($version->isPrerelease())->toBeFalse()
        ->and($version->tag())->toBe('v2.1.3')
        ->and($version->imageTags())->toBe(['2.1.3', '2.1', '2', 'latest']);
});

it('publishes only its own tag for a pre-release', function (string $input, ReleaseChannel $channel): void {
    $version = ReleaseVersion::parse($input);

    expect($version->channel())->toBe($channel)
        ->and($version->isPrerelease())->toBeTrue()
        ->and($version->imageTags())->toBe([$input]);
})->with([
    ['2.0.0-alpha.1', ReleaseChannel::Alpha],
    ['2.0.0-beta.1', ReleaseChannel::Beta],
    ['2.0.0-rc.1', ReleaseChannel::Rc],
    ['2.0.0-alpha.1-test', ReleaseChannel::Alpha],
    ['2.0.0-rc', ReleaseChannel::Rc],
]);

it('refuses versions outside the release grammar', function (string $input): void {
    expect(fn () => ReleaseVersion::parse($input))->toThrow(ReleaseException::class, 'Invalid release version');
})->with(['2.0', '2.0.0.1', 'v2.0.0', '02.0.0', '2.0.0-dev', '2.0.0-alpha.01', '2.0.0+build.1', '2.0.0-ALPHA.1', '', ' 2.0.0', "2.0.0\n", "2.0.0-rc.1\n"]);

it('reads release tags, which must start with v', function (): void {
    expect((string) ReleaseVersion::fromTag('v2.0.0-rc.1'))->toBe('2.0.0-rc.1')
        ->and((string) ReleaseVersion::fromInput('v2.0.0'))->toBe('2.0.0')
        ->and((string) ReleaseVersion::fromInput('2.0.0'))->toBe('2.0.0')
        ->and(fn () => ReleaseVersion::fromTag('2.0.0'))->toThrow(ReleaseException::class, 'start with "v"');
});

it('orders versions by semver precedence', function (): void {
    // Numeric identifiers rank below alphanumeric ones, so alpha.1-test sorts after every alpha.N (semver §11.4).
    $ordered = ['1.0.0', '2.0.0-alpha', '2.0.0-alpha.1', '2.0.0-alpha.1.test', '2.0.0-alpha.2', '2.0.0-alpha.10',
        '2.0.0-alpha.10.1', '2.0.0-alpha.1-test', '2.0.0-beta.1', '2.0.0-rc.1', '2.0.0', '2.0.1', '2.1.0', '10.0.0'];
    $shuffled = $ordered;
    shuffle($shuffled);
    usort($shuffled, static fn (string $a, string $b): int => ReleaseVersion::parse($a)->compare(ReleaseVersion::parse($b)));

    expect($shuffled)->toBe($ordered)
        ->and(ReleaseVersion::parse('2.0.0-rc.1')->compare(ReleaseVersion::parse('2.0.0-rc.1')))->toBe(0)
        ->and(ReleaseVersion::parse('2.0.0')->compare(ReleaseVersion::parse('2.0.0-rc.1')))->toBe(1)
        ->and(ReleaseVersion::parse('2.0.0-rc.1')->compare(ReleaseVersion::parse('2.0.0')))->toBe(-1);
});

it('uses the same version pattern as the manifest schema', function (): void {
    $schema = json_decode((string) file_get_contents(base_path('distribution/manifest.schema.json')), true);

    expect($schema['$defs']['version']['pattern'])->toBe(ReleaseVersion::PATTERN);
});
