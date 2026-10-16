<?php

declare(strict_types=1);

namespace App\Domain\Mail\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\Redactor;
use App\Domain\Identity\Models\User;
use App\Domain\Mail\Enums\SmtpEncryption;
use App\Domain\Mail\Models\MailSetting;
use App\Domain\Mail\Sending\SmtpHostGuard;
use BackedEnum;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator as ValidatorInstance;

/**
 * Saves the current organization's mail settings and audits the change (§8.11). An empty password
 * keeps the stored one, unless the connection (server, port, user name, encryption) changes: then the
 * password must be entered again, so a stored password is never sent to a server it was not entered for
 * (without a user name it is dropped instead). Credentials never reach the audit log: changed secrets
 * appear as "[redacted]" only.
 */
final readonly class SaveMailSettings
{
    private const array FIELDS = ['sender_name', 'reply_to_address', 'smtp_enabled', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_from_address'];

    private const array SECRETS = ['smtp_username', 'smtp_password'];

    /** Fields that decide where the stored password is sent. */
    private const array CONNECTION = ['smtp_host', 'smtp_port', 'smtp_username', 'smtp_encryption'];

    public function __construct(private AuditLogger $audit, private SmtpHostGuard $guard) {}

    /** @param  array<string, mixed>  $input */
    public function handle(array $input, ?User $actor = null): MailSetting
    {
        $settings = MailSetting::current() ?? new MailSetting;
        $data = $this->validate($input, $settings);
        $before = $settings->exists ? $this->snapshot($settings) : [];

        $settings->fill(Arr::only($data, self::FIELDS));
        if (filled($data['smtp_password'] ?? null)) {
            $settings->smtp_password = (string) $data['smtp_password'];
        } elseif ($settings->isDirty(self::CONNECTION)) {
            $settings->smtp_password = null; // connection changed while SMTP is off or without a user name
        }
        $settings->save();

        $after = $this->snapshot($settings);
        $changed = array_keys(array_diff_assoc(array_map(serialize(...), $after), array_map(serialize(...), $before)));
        if ($changed !== []) {
            $this->audit->record($before === [] ? 'mail_settings.created' : 'mail_settings.updated', $settings, [
                'before' => $this->present(Arr::only($before, $changed)),
                'after' => $this->present(Arr::only($after, $changed)),
            ], $actor);
        }

        return $settings;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function validate(array $input, MailSetting $current): array
    {
        $enabled = filter_var($input['smtp_enabled'] ?? false, FILTER_VALIDATE_BOOL);
        $required = $enabled ? 'required' : 'nullable';

        return Validator::make($input, [
            'sender_name' => ['nullable', 'string', 'max:120', 'not_regex:/[\x00-\x1F\x7F<>"]/'],
            'reply_to_address' => ['nullable', 'email:rfc', 'max:254'],
            'smtp_enabled' => ['boolean'],
            'smtp_host' => [$required, 'string', 'max:255', 'regex:/^(\[[0-9a-f:.]+\]|[A-Za-z0-9.-]+)$/i'],
            'smtp_port' => [$required, 'integer', 'between:1,65535'],
            'smtp_encryption' => [$required, Rule::enum(SmtpEncryption::class)],
            'smtp_username' => ['nullable', 'string', 'max:255'],
            'smtp_password' => ['nullable', 'string', 'max:255'],
            'smtp_from_address' => [$required, 'email:rfc', 'max:254'],
        ], [], $this->attributeNames())->after(function (ValidatorInstance $validator) use ($input, $enabled, $current): void {
            $host = $input['smtp_host'] ?? null;
            if ($enabled && is_string($host) && $host !== '' && ! $validator->errors()->has('smtp_host') && ! $this->guard->isAllowed($host)) {
                $validator->errors()->add('smtp_host', __('mail.settings.host_not_allowed'));
            }
            $port = $input['smtp_port'] ?? null;
            if ($enabled && is_numeric($port) && ! $validator->errors()->has('smtp_port') && ! $this->guard->isAllowedPort((int) $port)) {
                $validator->errors()->add('smtp_port', __('mail.settings.port_not_allowed'));
            }
            if ($enabled && $this->needsPasswordAgain($input, $current)) {
                $validator->errors()->add('smtp_password', __('mail.settings.password_required_again'));
            }
        })->validate();
    }

    /**
     * A stored password with a user name, a changed connection field and no new password.
     *
     * @param  array<string, mixed>  $input
     */
    private function needsPasswordAgain(array $input, MailSetting $current): bool
    {
        if (! $current->hasPassword() || filled($input['smtp_password'] ?? null)) {
            return false;
        }
        $username = array_key_exists('smtp_username', $input) ? $input['smtp_username'] : $current->smtp_username;
        if (blank($username)) {
            return false;
        }
        $stored = [
            'smtp_host' => $current->smtp_host, 'smtp_port' => $current->smtp_port,
            'smtp_username' => $current->smtp_username, 'smtp_encryption' => $current->smtp_encryption?->value,
        ];
        foreach (self::CONNECTION as $field) {
            $value = $input[$field] ?? null;
            $value = $value instanceof BackedEnum ? $value->value : $value;
            if (array_key_exists($field, $input) && (string) (is_scalar($value) ? $value : '') !== (string) $stored[$field]) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function snapshot(MailSetting $settings): array
    {
        $values = [];
        foreach (self::FIELDS as $field) {
            $value = $settings->getAttribute($field);
            $values[$field] = $value instanceof SmtpEncryption ? $value->value : $value;
        }
        foreach (self::SECRETS as $secret) {
            // Compared by fingerprint only, never stored or logged.
            $value = $settings->getAttribute($secret);
            $values[$secret] = filled($value) ? hash('sha256', (string) $value) : null;
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function present(array $values): array
    {
        foreach (self::SECRETS as $secret) {
            if (array_key_exists($secret, $values) && $values[$secret] !== null) {
                $values[$secret] = Redactor::MASK;
            }
        }

        return $values;
    }

    /** @return array<string, string> */
    private function attributeNames(): array
    {
        $names = [];
        foreach ([...self::FIELDS, 'smtp_password'] as $field) {
            $names[$field] = (string) __('mail.settings.'.$field);
        }

        return $names;
    }
}
