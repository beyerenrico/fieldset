<?php

declare(strict_types=1);

namespace Tests\Support\Docker;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * A clone-based 1.0.0 install for J9 (plan U19, ADR 0087), built from the local tag `v1.0.0` the way its install guide
 * says: the tag's files (`git archive`) in an `fsa-test-…` checkout of the harness workspace, `.env` from the production
 * template, `docker compose up -d --wait` in the checkout. Only names change, so clean-up finds everything: the image is
 * `<FIELDSET_TEST_IMAGE_REPOSITORY>-v1:1.0.0` (compose.yaml's `form-schema-app:local` is the dev stack's) and the Compose
 * project `fsa-test-j09-v1-<id>` (COMPOSE_PROJECT_NAME in `.env`, as an operator may set it). Compose runs with a clean
 * environment, so a COMPOSE_* variable of the test process never changes what 1.0 runs.
 */
final readonly class V1Install
{
    public const string TAG = 'v1.0.0';

    public function __construct(
        public string $checkout,
        public string $project,
        public string $image,
        public int $port,
        public string $appKey,
    ) {}

    public static function prepare(string $work, string $id): self
    {
        $checkout = $work.'/fsa-test-j09-v1-'.$id;
        if (! is_dir($checkout)) {
            mkdir($checkout, 0755, true);
        }
        $archive = $work.'/fsa-test-j09-v1-'.$id.'.tar';
        self::mustRun(['git', '-C', TestImage::repository(), 'archive', '--format=tar', '-o', $archive, self::TAG]);
        self::mustRun(['tar', '-xf', $archive, '-C', $checkout]);
        unlink($archive);
        $image = TestImage::repositoryName().'-v1:1.0.0';
        $compose = (string) file_get_contents($checkout.'/compose.yaml');
        file_put_contents($checkout.'/compose.yaml', str_replace('image: form-schema-app:local', 'image: '.$image, $compose));
        $install = new self($checkout, 'fsa-test-j09-v1-'.$id, $image, self::freePort(), 'base64:'.base64_encode(random_bytes(32)));
        $install->writeEnv();

        return $install;
    }

    /** The 1.0 image, built once per tag revision (BuildKit's cache makes a rebuild cheap). */
    public function build(): void
    {
        $inspect = new Process(['docker', 'image', 'inspect', $this->image]);
        $inspect->run();
        if ($inspect->isSuccessful()) {
            return;
        }
        $process = new Process(['docker', 'build', '--tag', $this->image, '--file', 'docker/app/Dockerfile', '.'], $this->checkout, ['DOCKER_BUILDKIT' => '1']);
        $process->setTimeout(3600);
        $process->run(static function (string $type, string $buffer): void {
            file_put_contents(TestImage::logFile('j09-v1-build.log'), $buffer, FILE_APPEND);
        });
        if (! $process->isSuccessful()) {
            throw new RuntimeException('Building the 1.0.0 image failed; see storage/agent-logs/j09-v1-build.log.');
        }
    }

    /** @param  list<string>  $arguments  `docker compose` in the checkout, as its operator runs it */
    public function compose(array $arguments, int $timeout = 900): Process
    {
        $process = new Process(['docker', 'compose', ...$arguments], $this->checkout, ['COMPOSE_FILE' => false, 'COMPOSE_PROJECT_NAME' => false, 'COMPOSE_PROFILES' => false, 'COMPOSE_DISABLE_ENV_FILE' => false]);
        $process->setTimeout($timeout);
        $process->run();

        return $process;
    }

    /** The container of a service of the 1.0 project (running or not). */
    public function container(string $service): string
    {
        $process = new Process(['docker', 'ps', '-aq', '--filter', 'label=com.docker.compose.project='.$this->project, '--filter', 'label=com.docker.compose.service='.$service, '--filter', 'label=com.docker.compose.oneoff=False']);
        $process->run();

        return trim($process->getOutput());
    }

    /** `php artisan …` in 1.0's running app container. */
    public function artisan(array $arguments, int $timeout = 600): Process
    {
        $process = new Process(['docker', 'exec', $this->container('app'), 'php', 'artisan', ...$arguments]);
        $process->setTimeout($timeout);
        $process->run();

        return $process;
    }

    /** One value of a query in a Postgres container (1.0's or the instance's), as its superuser over the socket. */
    public static function sql(string $container, string $sql): string
    {
        $process = new Process(['docker', 'exec', '-i', $container, 'psql', '-X', '-q', '-A', '-t', '-v', 'ON_ERROR_STOP=1', '-U', 'form_schema', '-d', 'form_schema', '-f', '-']);
        $process->setInput($sql);
        $process->setTimeout(300);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('psql failed: '.$process->getErrorOutput());
        }

        return trim($process->getOutput());
    }

    /**
     * Rows that prove the data: submissions, users and the head of every audit chain.
     *
     * @return array{submissions: int, users: int, heads: array<string, array{seq: int, hash: string}>}
     */
    public static function data(string $postgres): array
    {
        $heads = [];
        foreach (array_filter(explode("\n", self::sql($postgres, 'SELECT DISTINCT ON (COALESCE(organization_id, 0)) COALESCE(organization_id, 0), chain_seq, hash FROM activity_log WHERE chain_seq IS NOT NULL ORDER BY COALESCE(organization_id, 0), chain_seq DESC;'))) as $line) {
            [$chain, $seq, $hash] = explode('|', $line);
            $heads[$chain] = ['seq' => (int) $seq, 'hash' => $hash];
        }

        return ['submissions' => (int) self::sql($postgres, 'SELECT count(*) FROM submissions;'), 'users' => (int) self::sql($postgres, 'SELECT count(*) FROM users;'), 'heads' => $heads];
    }

    /** `docker compose down --volumes` of the 1.0 project, the image and the checkout. */
    public function remove(bool $image = true): void
    {
        $this->compose(['down', '--volumes', '--remove-orphans'], 300);
        if ($image) {
            new Process(['docker', 'image', 'rm', '-f', $this->image])->run();
        }
        new Process(['rm', '-rf', $this->checkout])->run();
    }

    private function writeEnv(): void
    {
        $values = [
            'APP_KEY' => $this->appKey, 'APP_DEBUG' => 'true', 'APP_URL' => 'http://localhost:'.$this->port, 'APP_PORT' => (string) $this->port,
            'COMPOSE_PROJECT_NAME' => $this->project, 'DB_PASSWORD' => bin2hex(random_bytes(16)), 'SESSION_SECURE_COOKIE' => 'false',
            'SANCTUM_STATEFUL_DOMAINS' => 'localhost:'.$this->port, 'MAIL_MAILER' => 'log', 'MAIL_HOST' => 'smtp.example.com', 'TRUSTED_PROXIES' => '*',
            'MAIL_FROM_ADDRESS' => '"forms@example.com"', 'MAIL_FROM_NAME' => '"Adopted Forms"',
        ];
        $env = (string) file_get_contents($this->checkout.'/.env.production.example');
        foreach ($values as $key => $value) {
            $env = preg_match('/^'.$key.'=/m', $env) === 1 ? (string) preg_replace('/^'.$key.'=.*$/m', $key.'='.addcslashes($value, '\\$'), $env) : $env.$key.'='.$value."\n";
        }
        file_put_contents($this->checkout.'/.env', $env);
        chmod($this->checkout.'/.env', 0600);
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $name = $socket === false ? '' : (string) stream_socket_get_name($socket, false);
        if ($socket !== false) {
            fclose($socket);
        }

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    /** @param  list<string>  $command */
    private static function mustRun(array $command): void
    {
        $process = new Process($command);
        $process->setTimeout(600);
        $process->mustRun();
    }
}
