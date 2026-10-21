<?php

declare(strict_types=1);

use Tests\Support\Instance\ConfigFixture;
use Tests\Support\Instance\TemporaryInstance;

/*
 * `fieldset config set webhooks on|paused` (plan U17, ADR 0085): the operator's confirmation after `fieldset install --from-backup`
 * that the old server no longer delivers. Only runtime.json changes (the queue reads it per job); no service is
 * recreated, and `config show` reports the value.
 */

beforeEach(function (): void {
    $this->config = new ConfigFixture;
});

afterEach(function (): void {
    TemporaryInstance::cleanup();
});

function runtimeWebhooks(ConfigFixture $config): mixed
{
    return json_decode((string) $config->instance->directory->read('runtime/runtime.json'), true)['webhooks'] ?? null;
}

it('pauses and resumes webhooks through runtime.json without recreating a service', function (): void {
    [$paused, $pausedJson] = $this->config->set('webhooks', 'paused');
    $afterPause = runtimeWebhooks($this->config);
    [$on, $onJson] = $this->config->set('webhooks', 'on');
    [, $show] = $this->config->show();

    expect($paused)->toBe(0, (string) json_encode($pausedJson))
        ->and($pausedJson['data']['services']['recreated'] ?? null)->toBe([])
        ->and($afterPause)->toBe(['paused' => true])
        ->and($on)->toBe(0)->and(runtimeWebhooks($this->config))->toBe(['paused' => false])
        ->and($this->config->state()->setting('webhooks_paused'))->toBeNull()
        ->and(collect($show['data']['keys'])->firstWhere('key', 'webhooks')['value'] ?? null)->toBe('on');
});

it('changes nothing when the value is already in effect, and refuses other values', function (): void {
    $mark = $this->config->mark();

    [$same, $sameJson] = $this->config->set('webhooks', 'on');
    [$bad, $badJson] = $this->config->set('webhooks', 'maybe');

    expect($same)->toBe(0)->and($sameJson['changed'])->toBeFalse()
        ->and($this->config->methodsSince($mark))->not->toContain('composeUp')
        ->and($bad)->toBe(2)->and($badJson['errors'][0]['code'])->toBe('invalid_value');
});
