<?php

declare(strict_types=1);

namespace App\Domain\Mail\Models;

use App\Domain\Identity\Tenancy\BelongsToOrganization;
use App\Domain\Mail\Policies\DocumentLinkPolicy;
use Database\Factories\DocumentLinkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An expiring download link for a document that was too large to attach (§8.8). Only the sha256
 * of the random URL token is stored; after expires_at the link answers 410 Gone. Rows are pruned
 * messaging.links.prune_after_days after expiry (then the link answers 404).
 *
 * @property int $id
 * @property int $organization_id
 * @property string $token_hash
 * @property string $disk
 * @property string $path
 * @property string $filename
 * @property string $mime
 * @property int $size
 * @property string|null $submission_uuid
 * @property int|null $rendition_id
 * @property Carbon $expires_at
 * @property Carbon|null $downloaded_at
 * @property int $download_count
 */
#[UseFactory(DocumentLinkFactory::class)]
#[UsePolicy(DocumentLinkPolicy::class)]
#[Fillable(['token_hash', 'disk', 'path', 'filename', 'mime', 'size', 'submission_uuid', 'rendition_id', 'expires_at'])]
#[Hidden(['token_hash'])]
class DocumentLink extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<DocumentLinkFactory> */
    use HasFactory;

    use MassPrunable;

    protected $attributes = ['submission_uuid' => null, 'rendition_id' => null, 'downloaded_at' => null, 'download_count' => 0];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'rendition_id' => 'integer',
            'expires_at' => 'datetime',
            'downloaded_at' => 'datetime',
            'download_count' => 'integer',
        ];
    }

    /**
     * Platform-level housekeeping across all organizations (`model:prune` runs without a tenant).
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        $days = max(1, (int) config('messaging.links.prune_after_days', 90));

        return static::query()->withoutTenant()->where('expires_at', '<', now()->subDays($days));
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function recordDownload(): void
    {
        $this->increment('download_count', 1, ['downloaded_at' => now()]);
    }
}
