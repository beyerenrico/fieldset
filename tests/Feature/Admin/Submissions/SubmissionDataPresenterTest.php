<?php

declare(strict_types=1);

use App\Domain\Submissions\Admin\SubmissionDataPresenter;
use App\Domain\Submissions\Admin\ValueFormatter;
use App\Domain\Submissions\Models\Attachment;
use FormSchema\Json\Json;
use Illuminate\Support\Str;

/* M3-T09 view model of the inbox detail: every element type, layout flattening, decimal strings. */

function presenterDefinition(): stdClass
{
    $el = fn (string $type, string $key, array $extra = []): array => ['type' => $type, 'key' => $key, 'label' => ucfirst(str_replace('_', ' ', $key)), 'id' => (string) Str::uuid(), ...$extra];

    return Json::decode((string) json_encode(['pages' => [
        ['key' => 'p1', 'title' => 'Page one', 'elements' => [
            $el('heading', 'intro_heading', ['text' => 'Intro']),
            $el('section', 'details', ['elements' => [
                $el('columns', 'cols', ['columns' => [['elements' => [$el('number', 'amount')]], ['elements' => [$el('money', 'price', ['currency' => 'EUR'])]]]]),
                $el('date', 'day'),
                $el('datetime', 'moment'),
            ]]),
            $el('multiselect', 'tags', ['options' => [['value' => 'a', 'label' => 'Alpha'], ['value' => 'b', 'label' => 'Beta']]]),
            $el('toggle', 'urgent'),
            $el('rating', 'stars', ['max' => 10]),
            $el('location', 'gps'),
            $el('calculated', 'share', ['expression' => '1', 'format' => 'text']),
            $el('text', 'never_filled'),
            $el('photo', 'photos', ['multiple' => true]),
            $el('file', 'manual'),
        ]],
        ['key' => 'p2', 'title' => 'Empty page', 'elements' => [$el('text', 'other')]],
        ['key' => 'p3', 'title' => 'Rows', 'elements' => [
            $el('repeater', 'lines', ['elements' => [$el('text', 'what'), $el('section', 'more', ['elements' => [$el('number', 'qty')]])]]),
        ]],
    ]]));
}

function presenterAttachment(string $uuid, string $mime, ?string $name): Attachment
{
    return (new Attachment)->forceFill(['uuid' => $uuid, 'mime' => $mime, 'original_name' => $name, 'kind' => 'photo']);
}

it('builds pages with grouped sections, flattened columns and formatted values', function (): void {
    $photo = '0199f2a0-0000-7000-8000-000000000001';
    $file = '0199f2a0-0000-7000-8000-000000000002';
    $presenter = new SubmissionDataPresenter(
        new ValueFormatter('de', 'Europe/Berlin'),
        [$photo => presenterAttachment($photo, 'image/jpeg', 'front.jpg'), $file => presenterAttachment($file, 'application/pdf', null)],
        fn (Attachment $a): string => 'https://files.test/'.$a->uuid,
    );
    $data = Json::decode((string) json_encode([
        'amount' => '1234567.5', 'price' => '-1000.00', 'day' => '2026-10-16', 'moment' => '2026-10-16T08:05:00Z',
        'tags' => ['b', 'zzz'], 'urgent' => false, 'stars' => 7, 'gps' => ['lat' => 52.52, 'lng' => 13.405, 'accuracy' => 12],
        'share' => null, 'photos' => [$photo, '0199f2a0-0000-7000-8000-00000000dead'], 'manual' => strtoupper($file),
        'lines' => [['what' => 'Kabel', 'qty' => '3'], ['what' => 'Dübel']],
    ]));

    $pages = $presenter->pages(presenterDefinition(), $data);

    expect(array_column($pages, 'title'))->toBe(['Page one', 'Rows']);
    $items = $pages[0]['items'];
    expect($items[0]['kind'])->toBe('group')
        ->and(array_column($items[0]['items'], 'value'))->toBe(['1.234.567,5', '-1.000,00 EUR', '16.10.2026', '16.10.2026 10:05'])
        ->and(array_column(array_slice($items, 1, 5), 'value'))->toBe(['Beta, zzz', 'Nein', '7 / 10', '52,52; 13,405 (± 12 m)', '—'])
        ->and(array_column($items, 'label'))->not->toContain('Never filled')
        ->and($items[6]['files'])->toBe([
            ['name' => 'front.jpg', 'url' => 'https://files.test/'.$photo, 'image' => true, 'missing' => false],
            ['name' => '0199f2a0-0000-7000-8000-00000000dead', 'url' => null, 'image' => false, 'missing' => true],
        ])
        ->and($items[7]['files'][0])->toMatchArray(['name' => 'photo 0199f2a0', 'image' => false, 'missing' => false]);

    $table = $pages[1]['items'][0];
    expect($table['kind'])->toBe('table')
        ->and($table['columns'])->toBe(['What', 'Qty'])
        ->and(array_map(fn (array $row): array => array_column($row, 'value'), $table['rows']))->toBe([['Kabel', '3'], ['Dübel', '—']]);
});

it('formats decimal strings without floats in English', function (): void {
    $formatter = new ValueFormatter('en', 'UTC');

    expect($formatter->decimal('123456789012345678901234567890.123456789'))->toBe('123,456,789,012,345,678,901,234,567,890.123456789')
        ->and($formatter->decimal('999'))->toBe('999')
        ->and($formatter->decimal('-0.50'))->toBe('-0.50')
        ->and($formatter->decimal('abc'))->toBe('abc')
        ->and($formatter->format((object) ['type' => 'checkbox'], true))->toBe('Yes')
        ->and($formatter->format((object) ['type' => 'calculated', 'format' => 'money', 'currency' => 'USD'], '1500.00'))->toBe('1,500.00 USD')
        ->and($formatter->format((object) ['type' => 'date'], 'not-a-date'))->toBe('not-a-date')
        ->and($formatter->format(Json::decode('{"type":"select","options":[{"value":"x","label":"Ex"}]}'), 'x'))->toBe('Ex');
});

it('renders a signature without a value object as empty', function (): void {
    $presenter = new SubmissionDataPresenter(new ValueFormatter('en', 'UTC'), [], fn (Attachment $a): string => '');
    $definition = Json::decode('{"pages":[{"key":"p","title":"P","elements":[{"type":"signature","key":"sig","label":"Sig","id":"0199f2a0-0000-7000-8000-000000000003"}]}]}');

    expect($presenter->pages($definition, Json::decode('{"sig":"garbage"}'))[0]['items'][0])->toBe(['kind' => 'field', 'label' => 'Sig', 'value' => '—']);
});
