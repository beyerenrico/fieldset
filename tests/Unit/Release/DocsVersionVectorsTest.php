<?php

declare(strict_types=1);

use App\Domain\Instance\Release\ReleaseException;
use App\Domain\Instance\Release\ReleaseVersion;

/*
 * The docs site orders release tags in JS (docs/site/lib/versions-reader.mjs) and, in the privileged store job, in jq
 * (docs/site/store/version-order.jq). Both run the shared vectors file in node tests; this test runs it through
 * ReleaseVersion, so all three agree on the grammar and the order (docs site plan U5, KTD "Version rules decide every
 * publish").
 */

/**
 * @return array{ascending: list<string>, noCopy: list<string>, invalid: list<string>,
 *     pairs: list<array{case: string, a: string, b: string, order: int}>}
 */
function docsVersionVectors(): array
{
    $json = (string) file_get_contents(base_path('docs/site/test/fixtures/version-vectors.json'));

    return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
}

function docsTagOrder(string $a, string $b): int
{
    return ReleaseVersion::fromTag($a)->compare(ReleaseVersion::fromTag($b)) <=> 0;
}

it('orders every tag of the ascending chain above all tags before it', function (): void {
    $chain = docsVersionVectors()['ascending'];

    expect(count($chain))->toBeGreaterThan(40)
        ->and(array_unique($chain))->toBe($chain);
    foreach ($chain as $i => $a) {
        foreach ($chain as $j => $b) {
            expect(docsTagOrder($a, $b))->toBe($i <=> $j, "{$a} against {$b}");
        }
    }
});

it('agrees with every named pair', function (): void {
    foreach (docsVersionVectors()['pairs'] as ['case' => $case, 'a' => $a, 'b' => $b, 'order' => $order]) {
        expect(docsTagOrder($a, $b))->toBe($order, $case);
    }
});

it('reads every no-copy tag as a valid release of the chain', function (): void {
    $vectors = docsVersionVectors();

    foreach ($vectors['noCopy'] as $tag) {
        expect($vectors['ascending'])->toContain($tag)
            ->and(ReleaseVersion::fromTag($tag)->tag())->toBe($tag);
    }
});

it('refuses every invalid tag', function (): void {
    foreach (docsVersionVectors()['invalid'] as $tag) {
        expect(fn () => ReleaseVersion::fromTag($tag))->toThrow(ReleaseException::class, null, json_encode($tag));
    }
});
