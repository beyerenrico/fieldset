<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Support\ActorUid;
use App\Console\Support\JsonResult;
use App\Domain\Audit\AuditLogger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * The audit entry of a successful `fieldset config set` (KTD26, ADR 0077), written in a socket-less one-off: event
 * `instance.config_changed` by the instance owner at the command line (actor `cli`, host UID), with the key, the old
 * and the new value and, when it moved, the instance URL. fieldset passes only plain settings (never a secret) and
 * redacts its known secrets before; the values are checked as plain single-line text here.
 */
#[Description('Audit a changed instance setting (fieldset config set)')]
#[Signature('instance:config-changed
        {--key= : The setting, e.g. domain}
        {--old= : Its previous value (empty for none)}
        {--new= : Its new value (empty for none)}
        {--old-url= : The previous instance URL, when the URL changed}
        {--new-url= : The new instance URL, when the URL changed}
        {--actor-uid= : Host UID of the operator running fieldset}
        {--json : Print one JSON document on stdout}')]
final class ConfigChangedCommand extends Command
{
    public const string EVENT = 'instance.config_changed';

    private const string NAME = 'instance:config-changed';

    private const int MAX_VALUE = 2048;

    public function handle(AuditLogger $audit): int
    {
        try {
            $uid = ActorUid::from($this->option('actor-uid'));
            $key = $this->option('key');
            if (! is_string($key) || preg_match('/^[a-z][a-z0-9-]{0,40}$/D', $key) !== 1) {
                throw new InvalidArgumentException('--key must name a setting, e.g. domain.');
            }
            $properties = ['key' => $key, 'from' => $this->value('old'), 'to' => $this->value('new')];
            $oldUrl = $this->value('old-url');
            $newUrl = $this->value('new-url');
            if ($oldUrl !== null || $newUrl !== null) {
                $properties['url'] = ['from' => $oldUrl, 'to' => $newUrl];
            }
        } catch (InvalidArgumentException $e) {
            if ($this->option('json') === true) {
                return JsonResult::failed(self::NAME, 'invalid_input', $e->getMessage())->emit($this);
            }
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
        $activity = $audit->cli('instance.config_changed', null, $properties, $uid);

        return $this->option('json') === true
            ? JsonResult::ok(self::NAME, ['event' => self::EVENT, 'activity_id' => $activity instanceof Model ? $activity->getKey() : null], changed: true)->emit($this)
            : self::SUCCESS;
    }

    /** An option as plain single-line text, null when empty. */
    private function value(string $option): ?string
    {
        $value = $this->option($option);
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || mb_strlen($value) > self::MAX_VALUE || preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) {
            throw new InvalidArgumentException("--{$option} must be plain single-line text.");
        }

        return $value;
    }
}
