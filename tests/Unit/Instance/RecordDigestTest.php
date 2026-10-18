<?php

declare(strict_types=1);

use App\Domain\Instance\Install\InstallRecord;
use App\Domain\Instance\Install\RecordDigest;
use App\Domain\Instance\State\EnvFiles;
use Tests\Support\Instance\TemporaryInstance;

/*
 * M9 gate fix (privacy): personal install inputs live in the state history and archived journals only as keyed
 * digests derived from APP_KEY; APP_PREVIOUS_KEYS still match, and plain values of earlier builds compare as before.
 */

afterEach(fn () => TemporaryInstance::cleanup());

function recordDigestFor(array $app): ?RecordDigest
{
    $env = new EnvFiles(TemporaryInstance::directory());
    $env->write('app', $app);

    return RecordDigest::fromEnvFiles($env);
}

it('digests with a key derived from APP_KEY, never the value itself', function (): void {
    $digest = recordDigestFor(['APP_KEY' => 'base64:'.base64_encode(str_repeat('k', 32))]);
    $other = recordDigestFor(['APP_KEY' => 'base64:'.base64_encode(str_repeat('o', 32))]);

    $value = $digest?->digest('owner@example.com');

    expect($value)->toStartWith(RecordDigest::PREFIX)
        ->and($value)->not->toContain('owner')
        ->and($value)->not->toBe($other?->digest('owner@example.com'))
        ->and($digest?->matches($value, 'owner@example.com'))->toBeTrue()
        ->and($digest?->matches($value, 'someone@example.com'))->toBeFalse()
        ->and(print_r($digest, true))->not->toContain('base64');
});

it('matches digests made with a previous key, and plain values recorded by earlier builds', function (): void {
    $old = 'base64:'.base64_encode(str_repeat('p', 32));
    $before = recordDigestFor(['APP_KEY' => $old])?->digest('Olivia Owner');
    $rotated = recordDigestFor(['APP_KEY' => 'base64:'.base64_encode(str_repeat('n', 32)), 'APP_PREVIOUS_KEYS' => 'base64:other,'.$old]);

    expect($rotated?->matches($before, 'Olivia Owner'))->toBeTrue()
        ->and($rotated?->matches('Olivia Owner', 'Olivia Owner'))->toBeTrue()
        ->and($rotated?->matches(null, 'Olivia Owner'))->toBeFalse();
});

it('has no digest before the instance has an APP_KEY, and then never claims a match', function (): void {
    $compare = InstallRecord::comparator(null);

    expect(recordDigestFor([]))->toBeNull()
        ->and($compare('owner_email', RecordDigest::PREFIX.str_repeat('a', 64), 'owner@example.com'))->toBeFalse()
        ->and($compare('owner_email', 'owner@example.com', 'owner@example.com'))->toBeTrue()
        ->and($compare('port', 8080, '8080'))->toBeTrue();
});
