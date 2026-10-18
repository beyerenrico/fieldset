<?php

declare(strict_types=1);

use App\Domain\Instance\State\InputGrammar;

/*
 * Plan U3: the strict grammar of every value the renderers take from the state (flags, backups, 1.0 .env files).
 */

it('accepts RFC 1123 host names and IP addresses', function (string $host): void {
    expect(InputGrammar::isHost($host))->toBeTrue();
})->with(['localhost', 'forms.example.com', 'a-b.c0.example', 'x', '192.168.1.20', '::1', 'fd00::1', str_repeat('a', 63).'.example.com']);

it('rejects anything else as a host name', function (string $host): void {
    expect(InputGrammar::isHost($host))->toBeFalse();
})->with([
    'empty' => [''], 'wildcard' => ['*.example.com'], 'underscore' => ['my_host'], 'leading hyphen' => ['-a.example'],
    'trailing hyphen' => ['a-.example'], 'long label' => [str_repeat('a', 64).'.example'], 'too long' => [str_repeat('abcdefghi.', 25).'example'],
    'bad dotted quad' => ['999.1.1.1'], 'space' => ['a b'], 'newline' => ["a\n"], 'url' => ['http://a'], 'port' => ['a:80'],
    'trailing dot' => ['example.com.'], 'quote' => ['a"b'],
]);

it('accepts http(s) URLs without credentials, query or fragment', function (string $url): void {
    expect(InputGrammar::isUrl($url))->toBeTrue();
})->with(['http://localhost:8080', 'https://forms.example.com', 'https://forms.example.com/', 'https://forms.example.com:8443/sub/path', 'http://[fd00::1]:8080', 'http://192.168.1.20']);

it('rejects unsafe or ambiguous URLs', function (string $url): void {
    expect(InputGrammar::isUrl($url))->toBeFalse();
})->with([
    'credentials' => ['https://u:p@example.com'], 'user only' => ['https://u@example.com'], 'ftp' => ['ftp://example.com'],
    'no host' => ['https:///path'], 'query' => ['https://example.com/?x'], 'fragment' => ['https://example.com/#x'],
    'port zero' => ['https://example.com:0'], 'port range' => ['https://example.com:65536'], 'dollar' => ['https://example.com/$x'],
    'quote' => ["https://example.com/'x"], 'backslash' => ['https://example.com\\x'], 'space' => ['https://exa mple.com'],
    'wildcard host' => ['https://*.example.com'], 'bare IPv6' => ['http://fd00::1'], 'relative' => ['/path'], 'tab' => ["https://example.com/\t"],
]);

it('checks ports, identifiers, e-mail addresses, text and host lists', function (): void {
    expect(InputGrammar::isPort(1))->toBeTrue()
        ->and(InputGrammar::isPort(65535))->toBeTrue()
        ->and(InputGrammar::isPort(0))->toBeFalse()
        ->and(InputGrammar::isPort('80'))->toBeFalse()
        ->and(InputGrammar::isIdentifier('form_schema'))->toBeTrue()
        ->and(InputGrammar::isIdentifier('Form-Schema'))->toBeFalse()
        ->and(InputGrammar::isEmail('forms@example.com'))->toBeTrue()
        ->and(InputGrammar::isEmail('forms+x@localhost'))->toBeTrue()
        ->and(InputGrammar::isEmail('a"b@example.com'))->toBeFalse()
        ->and(InputGrammar::isEmail('forms@*.example.com'))->toBeFalse()
        ->and(InputGrammar::isText('Formulare GmbH & Co. – Süd'))->toBeTrue()
        ->and(InputGrammar::isText("a\tb"))->toBeFalse()
        ->and(InputGrammar::isText('`id`'))->toBeFalse()
        ->and(InputGrammar::isText(str_repeat('a', 201)))->toBeFalse()
        ->and(InputGrammar::hostList('forms.lan, 192.168.1.20'))->toBe(['forms.lan', '192.168.1.20'])
        ->and(InputGrammar::hostList(''))->toBe([])
        ->and(InputGrammar::hostList('a,,b'))->toBeNull()
        ->and(InputGrammar::hostList('*.lan'))->toBeNull()
        ->and(InputGrammar::isSlug('my-instance-v2'))->toBeTrue()
        ->and(InputGrammar::isSlug('My_Instance'))->toBeFalse();
});

it('checks image references pinned by digest', function (): void {
    $digest = '@sha256:'.str_repeat('a', 64);

    expect(InputGrammar::isImageReference('ghcr.io/beyerenrico/form-schema-app'.$digest))->toBeTrue()
        ->and(InputGrammar::isImageReference('localhost:5000/fsa/app'.$digest))->toBeTrue()
        ->and(InputGrammar::isImageReference('postgres'.$digest))->toBeTrue()
        ->and(InputGrammar::isImageReference('postgres:16-alpine'))->toBeFalse()
        ->and(InputGrammar::isImageReference('evil${X}/app'.$digest))->toBeFalse()
        ->and(InputGrammar::isImageReference("app\n".$digest))->toBeFalse();
});

it('names the problem with a setting, or none for a valid one', function (string $key, mixed $value, ?string $problem): void {
    expect(InputGrammar::settingProblem($key, $value))->toBe($problem);
})->with([
    'port' => ['port', 8080, null],
    'null clears' => ['domain', null, null],
    'enum' => ['mail', 'capture', null],
    'bad enum' => ['mail', 'sendmail', 'mail must be one of capture, smtp'],
    'bad port' => ['smtp_port', 70000, 'smtp_port must be a port number (1–65535)'],
    'host' => ['smtp_host', 'smtp.example.com', null],
    'bad host' => ['domain', 'a b', 'domain must be a host name or IP address'],
    'lan address must be an IP' => ['lan_address', 'forms.lan', 'lan_address must be an IP address'],
    'identifier' => ['db_username', 'Robert"); DROP', 'db_username must be a lowercase identifier'],
    'email' => ['mail_from_address', 'nope', 'mail_from_address must be an e-mail address'],
    'text' => ['mail_from_name', 'a$b', 'mail_from_name must be plain text without quotes, $, backslashes or control characters'],
    'host list' => ['extra_hosts', '*.lan', 'extra_hosts must be a comma-separated list of host names or IP addresses'],
    'unknown text' => ['locale', 'de', null],
    'unknown bool' => ['update_check', true, null],
    'unknown array' => ['nested', ['a'], 'nested must be a string, number, boolean or null'],
    'bad key' => ['Bad Key', 'x', 'setting names must be lowercase identifiers'],
]);
