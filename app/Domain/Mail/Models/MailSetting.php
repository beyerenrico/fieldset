<?php

declare(strict_types=1);

namespace App\Domain\Mail\Models;

use App\Domain\Identity\Tenancy\BelongsToOrganization;
use App\Domain\Mail\Enums\SmtpEncryption;
use App\Domain\Mail\Policies\MailSettingPolicy;
use Database\Factories\MailSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-organization mail settings (§8.8): sender name, reply-to and an optional own SMTP server.
 * SMTP credentials use encrypted casts and are hidden from serialization; the password is never
 * shown back in the UI or written to the audit log.
 *
 * @property int $id
 * @property int $organization_id
 * @property string|null $sender_name
 * @property string|null $reply_to_address
 * @property bool $smtp_enabled
 * @property string|null $smtp_host
 * @property int|null $smtp_port
 * @property SmtpEncryption|null $smtp_encryption
 * @property string|null $smtp_username
 * @property string|null $smtp_password
 * @property string|null $smtp_from_address
 */
#[UseFactory(MailSettingFactory::class)]
#[UsePolicy(MailSettingPolicy::class)]
#[Fillable(['sender_name', 'reply_to_address', 'smtp_enabled', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_from_address'])]
#[Hidden(['smtp_username', 'smtp_password'])]
class MailSetting extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<MailSettingFactory> */
    use HasFactory;

    protected $attributes = [
        'sender_name' => null, 'reply_to_address' => null, 'smtp_enabled' => false, 'smtp_host' => null,
        'smtp_port' => null, 'smtp_encryption' => null, 'smtp_username' => null, 'smtp_password' => null,
        'smtp_from_address' => null,
    ];

    protected function casts(): array
    {
        return [
            'smtp_enabled' => 'boolean',
            'smtp_port' => 'integer',
            'smtp_encryption' => SmtpEncryption::class,
            'smtp_username' => 'encrypted',
            'smtp_password' => 'encrypted',
        ];
    }

    /** Settings of the current tenant, or null when the organization never saved any. */
    public static function current(): ?self
    {
        return static::query()->first();
    }

    /** The organization sends through its own SMTP server (and may use its own From address). */
    public function usesOwnSmtp(): bool
    {
        return $this->smtp_enabled && filled($this->smtp_host) && filled($this->smtp_from_address);
    }

    public function hasPassword(): bool
    {
        return filled($this->smtp_password);
    }
}
