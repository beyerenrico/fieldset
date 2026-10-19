<?php

declare(strict_types=1);

use App\Domain\Instance\Console\SmtpInput;
use App\Domain\Instance\Docker\ContainerSpec;
use App\Domain\Instance\Docker\DockerCommandFailed;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\Install\InstallSmoke;
use App\Domain\Instance\Operations\OperationJournal;
use App\Domain\Instance\State\EnvFiles;
use Illuminate\Support\Sleep;
use Tests\Support\Instance\ConfigFixture;
use Tests\Support\Instance\InstanceCommandFixture;
use Tests\Support\Instance\TemporaryInstance;

/*
 * R11 (ADR 0072 amendment, ADR 0080 amendment): the install's smoke test renders a PDF through Gotenberg and sends a
 * test mail to the owner, into the catcher (then found there by its Message-ID through the catcher's API) or through
 * the SMTP server of --smtp-host; each failure is exit 4 with its own code and resumes at the smoke test. Also the
 * resume and repeat rules of the SMTP flags, and that `fsa config set` verifies without either check.
 */

const SMOKE_SMTP_PASSWORD = 'smoke-smtp-secret-8d1f';

beforeEach(function (): void {
    $this->fixture = new InstanceCommandFixture;
    app()->instance(SmtpInput::STDIN, fn (): string => SMOKE_SMTP_PASSWORD);
});

afterEach(fn () => TemporaryInstance::cleanup());

/** @return array{0: int, 1: array<string, mixed>} */
function smokeSmtpInstall(InstanceCommandFixture $fixture, array $flags = []): array
{
    return $fixture->run('fsa:install', InstanceCommandFixture::installFlags(['--smtp-host' => 'smtp.example.com', '--smtp-username' => 'relay', '--password-stdin' => true, ...$flags]));
}

/** @return list<ContainerSpec> the smoke test's lookups of its test mail in the catcher */
function catcherLookups(InstanceCommandFixture $fixture): array
{
    return array_values(array_filter($fixture->runSpecs('curl'), static fn (ContainerSpec $spec): bool => InstanceCommandFixture::catcherLookup($spec) instanceof ProcessOutcome));
}

it('renders a PDF and finds its test mail in the catcher, the catcher login on stdin only (capture mode)', function (): void {
    [$code, $json] = $this->fixture->run('fsa:install', InstanceCommandFixture::installFlags());

    $state = $this->fixture->state();
    $lookups = array_values(array_filter($this->fixture->docker->called('runContainer'), static fn (array $call): bool => InstanceCommandFixture::catcherLookup($call['spec']) instanceof ProcessOutcome));
    $password = substr(new EnvFiles($this->fixture->directory)->read('mailpit')['MP_UI_AUTH'], 4);
    $smoke = new OperationJournal($this->fixture->directory)->history()[0]->phaseData('smoke');
    expect($code)->toBe(0, json_encode($json))
        ->and($this->fixture->oneOffCalls('instance:pdf-test')[0]['command'])->toBe(['php', 'artisan', 'instance:pdf-test', '--json'])
        ->and($this->fixture->oneOffCalls('instance:mail-test')[0]['command'])->toBe(['php', 'artisan', 'instance:mail-test', '--to=owner@example.com', '--install', '--json'])
        ->and($lookups)->toHaveCount(1)
        ->and($lookups[0]['spec']->network)->toBe($state->project().'_default')
        ->and($lookups[0]['spec']->labels)->toBe(Labels::forInstance($state->uuid(), 'smoke'))
        ->and($lookups[0]['spec']->command)->toBe(['--fail', '--silent', '--show-error', '--max-time', '10', '--config', '-', 'http://mailpit:8025/api/v1/messages?limit=50'])
        ->and($lookups[0]['input'])->toBe("user = \"fsa:{$password}\"\n")
        ->and(json_encode($lookups[0]['spec']))->not->toContain($password)
        ->and($smoke['checked'])->toContain('PDF through gotenberg (1834 bytes)', 'test mail through the mail catcher', 'test mail found in the mail catcher')
        ->and(json_encode($smoke))->not->toContain('owner@example.com');
});

it('fails the install with exit 4 pdf_render_failed when Gotenberg renders no PDF, and resumes at the smoke test', function (): void {
    $this->fixture->oneOffs['instance:pdf-test'] = InstanceCommandFixture::oneOffJson(['command' => 'instance:pdf-test', 'status' => 'failed',
        'errors' => [['code' => 'pdf_render_failed', 'message' => 'Gotenberg did not render the test PDF: connection refused', 'hint' => null, 'retryable' => true]]]);

    [$code, $json] = $this->fixture->run('fsa:install', InstanceCommandFixture::installFlags());
    $journal = new OperationJournal($this->fixture->directory)->current();
    unset($this->fixture->oneOffs['instance:pdf-test']);
    [$resumed, $again] = $this->fixture->run('fsa:install', InstanceCommandFixture::installFlags());

    expect($code)->toBe(4)
        ->and($json['errors'][0])->toMatchArray(['code' => 'pdf_render_failed', 'retryable' => true])
        ->and($json['errors'][0]['hint'])->toContain('resumes at the smoke test')
        ->and($json['data']['output'])->toContain('connection refused')
        ->and($journal?->lastStartedPhase())->toBe('smoke')
        ->and($this->fixture->oneOffCalls('instance:mail-test'))->toHaveCount(1)
        ->and($resumed)->toBe(0, json_encode($again));
});

it('fails with exit 4 test_mail_not_captured when the catcher never lists the test mail', function (?ProcessOutcome $answer, string $output): void {
    Sleep::fake();
    $this->fixture->catcherOutcome = $answer;

    [$code, $json] = $this->fixture->run('fsa:install', InstanceCommandFixture::installFlags());

    expect($code)->toBe(4)
        ->and($json['errors'][0])->toMatchArray(['code' => 'test_mail_not_captured', 'retryable' => true])
        ->and($json['data']['output'])->toContain($output)
        ->and(catcherLookups($this->fixture))->toHaveCount(InstallSmoke::CATCHER_ATTEMPTS)
        ->and(new OperationJournal($this->fixture->directory)->current()?->lastStartedPhase())->toBe('smoke');
    Sleep::assertSleptTimes(InstallSmoke::CATCHER_ATTEMPTS - 1);
})->with([
    'another message only' => [new ProcessOutcome(0, '{"messages":[{"MessageID":"other@localhost"}]}'), 'not among the newest 50 messages'],
    'catcher unreachable' => [new ProcessOutcome(7, '', 'curl: (7) Failed to connect to mailpit port 8025'), 'Failed to connect to mailpit'],
]);

it('sends the test mail through the SMTP server without a catcher lookup', function (): void {
    [$code, $json] = smokeSmtpInstall($this->fixture);

    $smoke = new OperationJournal($this->fixture->directory)->history()[0]->phaseData('smoke');
    expect($code)->toBe(0, json_encode($json))
        ->and(catcherLookups($this->fixture))->toBe([])
        ->and($smoke['checked'])->toContain('test mail through smtp://smtp.example.com:587')->not->toContain('test mail found in the mail catcher');
});

it('fails the install with exit 4 test_mail_failed when the SMTP server refuses, never naming the owner or the password', function (): void {
    $this->fixture->oneOffs['instance:mail-test'] = InstanceCommandFixture::oneOffJson(['command' => 'instance:mail-test', 'status' => 'failed',
        'errors' => [['code' => 'test_mail_failed', 'message' => 'The SMTP server did not accept the test mail: 550 5.1.1 <Owner@Example.com>: Recipient address rejected', 'hint' => null, 'retryable' => false]]]);

    [$code, $json] = smokeSmtpInstall($this->fixture);
    $journal = new OperationJournal($this->fixture->directory)->current();
    unset($this->fixture->oneOffs['instance:mail-test']);
    [$resumed, $again] = smokeSmtpInstall($this->fixture);

    expect($code)->toBe(4)
        ->and($json['errors'][0])->toMatchArray(['code' => 'test_mail_failed', 'message' => 'The test mail through smtp://smtp.example.com:587 was not accepted.', 'retryable' => true])
        ->and($json['errors'][0]['hint'])->toContain('resumes at the smoke test')->toContain('fsa uninstall --yes')->toContain('fsa config set mail smtp')->toContain('forms@localhost')
        ->and($json['data']['output'])->toContain('550 5.1.1 <<owner-email>>')
        ->and(json_encode($json))->not->toContain('owner@example.com')->not->toContain(SMOKE_SMTP_PASSWORD)
        ->and($journal?->lastStartedPhase())->toBe('smoke')
        ->and(json_encode($journal?->toArray()))->not->toContain(SMOKE_SMTP_PASSWORD)->not->toContain('Recipient address rejected')
        ->and($resumed)->toBe(0, json_encode($again))
        ->and($this->fixture->oneOffCalls('instance:mail-test'))->toHaveCount(2);
});

it('needs the SMTP password again on a resume before the state phase rendered it', function (): void {
    $this->fixture->docker->failOn('composeConfig', new DockerCommandFailed('compose config', new ProcessOutcome(1, '', 'invalid compose file')));
    [$code] = smokeSmtpInstall($this->fixture);
    unset($this->fixture->docker->failures['composeConfig']);

    [$without, $refused] = $this->fixture->run('fsa:install', InstanceCommandFixture::installFlags(['--smtp-host' => 'smtp.example.com']));
    [$with, $json] = smokeSmtpInstall($this->fixture);

    expect($code)->not->toBe(0)
        ->and($without)->toBe(2)
        ->and($refused['errors'][0]['code'])->toBe('password_required')
        ->and($refused['errors'][0]['message'])->toContain('needs the SMTP password again')
        ->and($with)->toBe(0, json_encode($json))
        ->and(new EnvFiles($this->fixture->directory)->read('app')['MAIL_PASSWORD'] ?? null)->toBe(SMOKE_SMTP_PASSWORD);
});

it('refuses other SMTP settings or another account on a resume after the phase that applied them', function (array $flags, ?string $password, string $flag): void {
    $this->fixture->smokeOutcome = new ProcessOutcome(22, '', 'curl: (22) The requested URL returned error: 500');
    smokeSmtpInstall($this->fixture);
    $this->fixture->smokeOutcome = null;
    if ($password !== null) {
        app()->instance(SmtpInput::STDIN, fn (): string => $password);
    }

    [$code, $json] = smokeSmtpInstall($this->fixture, $flags);

    expect($code)->toBe(2)
        ->and($json['errors'][0]['code'])->toBe('flag_already_applied')
        ->and($json['errors'][0]['message'])->toContain($flag)
        ->and(json_encode($json))->not->toContain('other-secret');
})->with([
    'another server' => [['--smtp-host' => 'smtp2.example.com'], null, '--smtp-host'],
    'another port' => [['--smtp-port' => '2525'], null, '--smtp-port'],
    'another password' => [[], 'other-secret', '--smtp-username'],
    'another user name' => [['--smtp-username' => 'relay2'], null, '--smtp-username'],
]);

it('changes nothing on a finished instance with the same SMTP flags and password, and names config set mail for others', function (): void {
    smokeSmtpInstall($this->fixture);
    $calls = count($this->fixture->docker->calls);

    [$sameCode, $same] = smokeSmtpInstall($this->fixture, ['--smtp-encryption' => 'starttls', '--smtp-port' => '587']);
    [$bareCode] = $this->fixture->run('fsa:install', InstanceCommandFixture::installFlags());
    app()->instance(SmtpInput::STDIN, fn (): string => 'rotated-secret');
    [$otherCode, $other] = smokeSmtpInstall($this->fixture, ['--smtp-host' => 'smtp2.example.com']);

    expect($sameCode)->toBe(0, json_encode($same))
        ->and($same['changed'])->toBeFalse()
        ->and($bareCode)->toBe(0)
        ->and($this->fixture->docker->methodsSince($calls))->toBe([])
        ->and($otherCode)->toBe(2)
        ->and($other['errors'][0]['code'])->toBe('use_config_set')
        ->and($other['data']['flags'])->toBe([['flag' => '--smtp-host', 'key' => 'mail'], ['flag' => '--smtp-username', 'key' => 'mail']])
        ->and($other['errors'][0]['hint'])->toContain('fsa config set mail smtp')
        ->and(json_encode($other))->not->toContain('rotated-secret');
});

it('names config set mail when --smtp-host is added to an instance installed in capture mode', function (): void {
    $this->fixture->run('fsa:install', InstanceCommandFixture::installFlags());

    [$code, $json] = $this->fixture->run('fsa:install', InstanceCommandFixture::installFlags(['--smtp-host' => 'smtp.example.com']));

    expect($code)->toBe(2)
        ->and($json['errors'][0]['code'])->toBe('use_config_set')
        ->and($json['data']['flags'])->toBe([['flag' => '--smtp-host', 'key' => 'mail']])
        // M11 gate: the same command as the capture banner, sender included (forms@localhost is refused by most servers).
        ->and($json['errors'][0]['hint'])->toContain('`fsa config set mail smtp --smtp-host <host> --smtp-username <user> --from-address <address> --password-stdin`');
});

it('verifies `fsa config set` without rendering a PDF or sending a mail (only the install smoke test does)', function (): void {
    $config = new ConfigFixture;
    $mark = $config->mark();

    [$code, $json] = $config->set('port', '8091', ['--yes' => true]);
    $oneOffs = array_map(static fn (array $call): string => $call['command'][2] ?? '', $config->callsSince($mark, 'composeRun'));

    expect($code)->toBe(0, json_encode($json))
        ->and($oneOffs)->not->toContain('instance:pdf-test')->not->toContain('instance:mail-test')
        ->and(array_filter($config->callsSince($mark, 'runContainer'), static fn (array $call): bool => InstanceCommandFixture::catcherLookup($call['spec']) instanceof ProcessOutcome))->toBe([]);
});
