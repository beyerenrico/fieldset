<?php

declare(strict_types=1);

namespace App\Domain\Instance\Install;

use App\Domain\Instance\Compose\Services\MailpitService;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Docker\ContainerSpec;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Lifecycle\AppCommands;
use App\Domain\Instance\Lifecycle\OneOffResult;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Output\ProgressReporter;
use App\Domain\Instance\Runtime\CatcherRoute;
use App\Domain\Instance\State\EnvFiles;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceSecrets;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;
use App\Domain\Instance\State\InvalidState;
use Illuminate\Support\Sleep;

/**
 * The install's smoke test (R11, ADR 0072 amendment): SmokeTest's HTTP checks (`/up`, the admin login, Gotenberg's
 * health, the edge or the local CA), then what only an install checks, each in a socket-less one-off of the app role:
 *
 * - a one-page PDF through the app's own Gotenberg client (`instance:pdf-test`; exit 4 `pdf_render_failed`);
 * - a test mail to the owner through the instance mailer (`instance:mail-test --install`; exit 4 `test_mail_failed`):
 *   into the bundled catcher in capture mode, through the server of `--smtp-host` in SMTP mode. Without an owner it goes
 *   to the active operator; data from a 1.0 install may have none, and the mail is then skipped (MAIL_SKIPPED, ADR 0087).
 *   In capture mode a throwaway `curl` container on the default network then finds it by its Message-ID in the catcher's
 *   API (exit 4 `test_mail_not_captured`); the catcher's login reaches curl on stdin, never on a command line.
 *
 * `fieldset config set` verifies with SmokeTest alone: a settings change renders no PDF and mails nobody (`config set mail
 * smtp` sends its own test mail, ADR 0080). The owner's address stays out of messages and phase data (journaled).
 */
final readonly class InstallSmoke
{
    /** What `checked` lists when no owner was given and the data has no active operator to mail (ADR 0087). */
    public const string MAIL_SKIPPED = 'test mail skipped: no owner given and no active operator';

    /** Lookups of the test mail in the catcher, one second apart (it is stored before the catcher answers 250). */
    public const int CATCHER_ATTEMPTS = 5;

    private const int CATCHER_DELAY = 1;

    private const float CATCHER_TIMEOUT = 30.0;

    private const string RESUME = 'then run `fieldset install` again with the same flags: it resumes at the smoke test.';

    public function __construct(private SmokeTest $smoke, private AppCommands $app, private DockerGateway $docker, private InstanceDirectory $directory) {}

    /** @return array<string, mixed> the smoke phase's data: what was checked (and the local CA's fingerprint) */
    public function run(InstanceState $state, ComposeProject $project, ?string $owner, ?ProgressReporter $progress = null, bool $internalOnly = false): array
    {
        // `--from-backup` with a certificate pending (ADR 0085): the app and Gotenberg on the internal network only.
        $result = $internalOnly ? ['checked' => $this->smoke->internal($state, $project)] : $this->smoke->run($state, $project, $progress);
        $checked = is_array($result['checked'] ?? null) ? $result['checked'] : [];

        return [...$result, 'checked' => [...$checked, $this->pdf($project, $progress), ...$this->mail($state, $project, $owner, $progress)]];
    }

    /** The longest the install's smoke test can take for this state. */
    public static function maxSeconds(InstanceState $state): int
    {
        $catcher = InstanceSettings::of($state)->capturesMail() ? self::CATCHER_ATTEMPTS * ((int) self::CATCHER_TIMEOUT + self::CATCHER_DELAY) : 0;

        return SmokeTest::maxSeconds($state) + (int) AppCommands::PDF_TEST_TIMEOUT + (int) AppCommands::MAIL_TEST_TIMEOUT + $catcher;
    }

    /** Where instance mail goes: the bundled catcher, or the SMTP server (never a credential). */
    public static function route(InstanceState $state): string
    {
        $settings = InstanceSettings::of($state);
        $scheme = $settings->smtpScheme() === InstanceSettings::SMTP_IMPLICIT_TLS ? 'smtps' : 'smtp';

        return $settings->capturesMail() ? 'the mail catcher' : "{$scheme}://{$settings->smtpHost()}:{$settings->smtpPort()}";
    }

    private function pdf(ComposeProject $project, ?ProgressReporter $progress): string
    {
        $progress?->event('smoke', ProgressReporter::INFO, 'Rendering a test PDF through Gotenberg');
        $result = $this->app->pdfTest($project);
        if (! $result->ok) {
            throw new InstanceException('The test PDF did not render through Gotenberg.', ExitCode::HealthCheckFailed, 'pdf_render_failed',
                'Read `fieldset logs gotenberg` and `fieldset logs app`, fix the cause, '.self::RESUME, true, ['code' => $result->errorCode(), 'output' => $result->errorMessage()]);
        }
        $bytes = is_int($result->data['bytes'] ?? null) ? $result->data['bytes'] : 0;

        return "PDF through gotenberg ({$bytes} bytes)";
    }

    /** @return list<string> */
    private function mail(InstanceState $state, ComposeProject $project, ?string $owner, ?ProgressReporter $progress): array
    {
        $route = self::route($state);
        $progress?->event('smoke', ProgressReporter::INFO, "Sending a test mail to the owner through {$route}");
        $result = $this->app->mailTest($project, $owner, install: true);
        if (! $result->ok && $owner === null && $result->errorCode() === 'invalid_input') {
            return [self::MAIL_SKIPPED]; // data from a 1.0 install (adopt, `--from-backup`) may have no operator yet (plan U19)
        }
        if (! $result->ok) {
            throw new InstanceException("The test mail through {$route} was not accepted.", ExitCode::HealthCheckFailed, 'test_mail_failed', $this->mailHint($state), true,
                ['code' => $result->errorCode(), 'output' => $owner === null ? $result->errorMessage() : str_ireplace($owner, '<owner-email>', $result->errorMessage())]);
        }
        if (! InstanceSettings::of($state)->capturesMail()) {
            return ["test mail through {$route}"];
        }
        $this->awaitCaptured($state, $project, $this->messageId($result), $progress);

        return ["test mail through {$route}", 'test mail found in the mail catcher'];
    }

    private function mailHint(InstanceState $state): string
    {
        $settings = InstanceSettings::of($state);
        if ($settings->capturesMail()) {
            return 'Read `fieldset logs mailpit` and `fieldset logs app`, fix the cause, '.self::RESUME;
        }
        $sender = str_ends_with($settings->mailFromAddress(), '@localhost') ? " Mail is sent from {$settings->mailFromAddress()}, which most servers refuse (--from-address)." : '';

        return 'Check the SMTP server, port, encryption and account in the output.'.$sender.' A server that was briefly unreachable: '.self::RESUME
            .' Other settings or another account: `fieldset uninstall --yes` and install again with the corrected --smtp-* flags, or install without them (capture mode) and switch with `fieldset config set mail smtp`, which tests the server and keeps capture mode when it refuses.';
    }

    private function messageId(OneOffResult $result): string
    {
        $id = $result->data['message_id'] ?? null;
        if (! is_string($id) || preg_match('/^[0-9a-f]{32}@[A-Za-z0-9.-]+$/D', $id) !== 1) {
            throw new InstanceException('The test mail carries no Message-ID to look up in the mail catcher.', ExitCode::HealthCheckFailed, 'test_mail_failed', 'Install the release whose image runs this fieldset (install.sh pulls both together).', false);
        }

        return $id;
    }

    /** Asks the catcher's API (newest first) for the test mail until it is there. */
    private function awaitCaptured(InstanceState $state, ComposeProject $project, string $messageId, ?ProgressReporter $progress): void
    {
        $progress?->event('smoke', ProgressReporter::INFO, 'Looking for the test mail in the mail catcher');
        $webroot = $state->mode() === 'domain' ? CatcherRoute::MAIL_PATH : '/';
        $url = 'http://'.MailpitService::NAME.':'.MailpitService::UI_PORT.$webroot.'api/v1/messages?limit=50';
        $spec = new ContainerSpec(
            image: $state->digests()['app'],
            command: ['--fail', '--silent', '--show-error', '--max-time', '10', '--config', '-', $url],
            labels: Labels::forInstance($state->uuid(), SmokeTest::ROLE),
            network: $project->name.'_default',
            entrypoint: 'curl',
        );
        $login = 'user = "'.InstanceSecrets::CATCHER_USER.':'.$this->catcherPassword()."\"\n";
        $output = '';
        for ($attempt = 1; $attempt <= self::CATCHER_ATTEMPTS; $attempt++) {
            $outcome = $this->docker->runContainer($spec, $login, self::CATCHER_TIMEOUT);
            if ($outcome->successful() && $this->listed($outcome->stdout, $messageId)) {
                return;
            }
            $output = trim(($outcome->successful() ? 'not among the newest 50 messages' : $outcome->stdout)."\n".$outcome->stderrTail(3));
            if ($attempt < self::CATCHER_ATTEMPTS) {
                Sleep::for(self::CATCHER_DELAY)->seconds();
            }
        }
        throw new InstanceException('The test mail was accepted but did not show up in the mail catcher.', ExitCode::HealthCheckFailed, 'test_mail_not_captured',
            'Read `fieldset logs mailpit`, fix the cause, '.self::RESUME, true, ['output' => $output]);
    }

    private function catcherPassword(): string
    {
        $catcher = new EnvFiles($this->directory)->read(MailpitService::NAME)['MP_UI_AUTH'] ?? '';
        $prefix = InstanceSecrets::CATCHER_USER.':';

        return str_starts_with($catcher, $prefix) && strlen($catcher) > strlen($prefix)
            ? substr($catcher, strlen($prefix))
            : throw new InvalidState('env/mailpit.env holds no catcher login (MP_UI_AUTH).');
    }

    /** Whether the catcher's message list names the Message-ID (Mailpit keeps it without angle brackets). */
    private function listed(string $json, string $messageId): bool
    {
        $list = json_decode($json, true);
        $messages = is_array($list) && is_array($list['messages'] ?? null) ? $list['messages'] : [];

        return array_any($messages, static fn (mixed $message): bool => is_array($message) && is_string($message['MessageID'] ?? null)
            && strtolower(trim($message['MessageID'], '<> ')) === strtolower($messageId));
    }
}
