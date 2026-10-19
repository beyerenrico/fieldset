<?php

declare(strict_types=1);

use App\Domain\Instance\Restore\DumpInspector;

/*
 * The dump check before pg_restore (R41, KTD18, plan U17, ADR 0085): the table of contents of a real dump of this app
 * passes; an extension other than pg_trgm, an event trigger, other object types and schemas refuse it; an archive
 * without a valid HMAC may name only the app's own routines and triggers.
 */

function realListing(): string
{
    return (string) file_get_contents(base_path('tests/Fixtures/restore/pg-restore-list.txt'));
}

it('passes the table of contents of a real dump of this app, strict or not', function (): void {
    $plain = DumpInspector::inspect(realListing(), strict: false);
    $strict = DumpInspector::inspect(realListing(), strict: true);

    expect($plain['rejected'])->toBe([])
        ->and($strict['rejected'])->toBe([])
        ->and($plain['entries'])->toBe(607)
        ->and($plain['types'])->toMatchArray(['EXTENSION' => 1, 'FUNCTION' => 8, 'TABLE DATA' => 57, 'TRIGGER' => 5]);
});

it('refuses objects a backup of this app never holds', function (string $line, string $problem): void {
    $result = DumpInspector::inspect(realListing().$line."\n", strict: false);

    expect($result['rejected'])->toHaveCount(1)
        ->and($result['rejected'][0])->toStartWith($problem);
})->with([
    'another extension' => ['9001; 3079 1 EXTENSION - dblink ', 'an extension other than pg_trgm'],
    'adminpack' => ['9002; 3079 1 EXTENSION - adminpack ', 'an extension other than pg_trgm'],
    'an event trigger' => ['9003; 3466 1 EVENT TRIGGER - grab_ddl postgres', 'an object type a backup of this app never has (EVENT TRIGGER)'],
    'a rule' => ['9004; 2618 1 RULE public users steal postgres', 'an object type a backup of this app never has (RULE)'],
    'a foreign server' => ['9005; 1417 1 SERVER - remote postgres', 'an object type a backup of this app never has (SERVER)'],
    'a procedural language' => ['9006; 2612 1 PROCEDURAL LANGUAGE - plperlu postgres', 'an object type a backup of this app never has (PROCEDURAL LANGUAGE)'],
    'a policy' => ['9007; 3256 1 POLICY public users p postgres', 'an object type a backup of this app never has (POLICY)'],
    'another schema' => ['9008; 1259 1 TABLE evil stash postgres', 'an object outside schema public'],
    'an unreadable line' => ['garbage line', 'an entry that cannot be read'],
]);

it('refuses unknown routines and triggers only in an unauthenticated archive', function (): void {
    $extra = "9009; 1255 1 FUNCTION public steal_secrets() postgres\n9010; 2620 1 TRIGGER public users users_steal_trg postgres\n";

    expect(DumpInspector::inspect(realListing().$extra, strict: false)['rejected'])->toBe([])
        ->and(DumpInspector::inspect(realListing().$extra, strict: true)['rejected'])->toHaveCount(2);
});

it('lists every routine and trigger the migrations create', function (): void {
    $sql = implode("\n", array_map(file_get_contents(...), glob(base_path('database/migrations/*.php')) ?: []));
    preg_match_all('/CREATE (?:OR REPLACE )?FUNCTION\s+(\w+)\s*\(/i', $sql, $functions);
    preg_match_all('/CREATE TRIGGER\s+(\w+)/i', $sql, $triggers);

    expect(array_values(array_unique($functions[1])))->toEqualCanonicalizing(DumpInspector::ROUTINES)
        ->and(array_values(array_unique($triggers[1])))->toEqualCanonicalizing(DumpInspector::TRIGGERS);
});
