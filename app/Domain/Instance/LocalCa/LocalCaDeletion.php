<?php

declare(strict_types=1);

namespace App\Domain\Instance\LocalCa;

use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceState;

/**
 * Deleting the local CA once `phone-https off` runs (R19, ADR 0082 amendment, M11 gate). A deletion that fails (the
 * volume read-only, the engine gone) is recorded in the state (`local_ca_delete: pending`), so it is not lost behind a
 * warning: `fieldset status` reports it, the next `config set phone-https off` retries it (a change of that setting, so it
 * runs under the lock and journal like any other), and `config set phone-https local-ca` deletes the old CA before
 * Caddy would pick it up again (exit 3 when it cannot).
 */
final readonly class LocalCaDeletion
{
    public const string SETTING = 'local_ca_delete';

    public const string PENDING = 'pending';

    public const string RETRY_COMMAND = 'fieldset config set phone-https off';

    public function __construct(private InstanceDirectory $directory, private LocalCaStore $store) {}

    public static function pending(InstanceState $state): bool
    {
        return $state->setting(self::SETTING) === self::PENDING;
    }

    /**
     * Deletes the CA of `$state`'s edge volume and records the outcome in the current state. Never throws: a failure is
     * the `local_ca_delete_failed` warning with the retry command.
     *
     * @return array{data: array<string, mixed>, warnings: list<array{code: string, message: string, hint: string|null}>}
     */
    public function delete(InstanceState $state): array
    {
        try {
            $this->store->deleteKey($state);
        } catch (InstanceException $e) {
            $this->record(true);

            return ['data' => ['phone_https' => ['mode' => null, 'ca_deleted' => false]], 'warnings' => [
                ['code' => 'local_ca_delete_failed', 'message' => $e->getMessage(), 'hint' => 'Run `'.self::RETRY_COMMAND.'` again to delete it; until then its keys stay on the edge\'s certificate volume (`fieldset uninstall` removes the volume).'],
            ]];
        }
        $this->record(false);

        return ['data' => ['phone_https' => ['mode' => null, 'ca_deleted' => true]], 'warnings' => [
            ['code' => 'local_ca_deleted', 'message' => 'The local CA and its keys were deleted. Remove its certificate from the phones that trusted it.', 'hint' => null],
        ]];
    }

    /**
     * Before `local-ca` starts an edge: a CA a failed deletion left behind goes first, so Caddy creates a new one.
     *
     * @throws InstanceException exit 3 `local_ca_delete_failed`, nothing changed
     */
    public function beforeNewCa(InstanceState $state): void
    {
        if (! self::pending($state)) {
            return;
        }

        try {
            $this->store->deleteKey($state);
        } catch (InstanceException $e) {
            throw new InstanceException($e->getMessage(), ExitCode::PreflightFailed, 'local_ca_delete_failed', 'The previous local CA must go before a new one is created; check `fieldset status` and the Docker engine, then run the command again.', true, [], $e);
        }
    }

    private function record(bool $pending): void
    {
        $state = $this->directory->state();
        if (self::pending($state) !== $pending) {
            $this->directory->saveState($state->withSetting(self::SETTING, $pending ? self::PENDING : null));
        }
    }
}
