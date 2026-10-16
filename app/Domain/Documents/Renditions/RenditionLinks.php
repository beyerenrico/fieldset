<?php

declare(strict_types=1);

namespace App\Domain\Documents\Renditions;

use App\Domain\Documents\Models\Rendition;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * Short-lived signed download URLs for renditions (§10, §11 "private storage with signed, expiring
 * URLs only"). The link names the user it was issued to; the download re-checks that user's access.
 */
final class RenditionLinks
{
    public static function expiresAt(): Carbon
    {
        return Carbon::now()->addMinutes(max(1, (int) config('documents.link_ttl_minutes', 5)));
    }

    public static function temporaryUrl(Rendition $rendition, User $user, ?Carbon $expiresAt = null): string
    {
        return URL::temporarySignedRoute('documents.download', $expiresAt ?? self::expiresAt(), [
            'rendition' => $rendition->uuid,
            'user' => $user->id,
        ]);
    }

    public static function fileName(Rendition $rendition, ?string $number): string
    {
        $base = $number !== null && $number !== '' ? (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $number) : $rendition->uuid;

        return $base.'.pdf';
    }
}
