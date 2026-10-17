<?php

declare(strict_types=1);

namespace App\Support\Security;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Local initials avatar as an inline SVG data URI. Filament's default UiAvatarsProvider sends every user's
 * name to ui-avatars.com (a third party, §11 Privacy) and is blocked by the panel CSP (`img-src 'self' data: blob:`).
 */
final class InitialsAvatarProvider implements AvatarProvider
{
    private const string BACKGROUND = '#18181b';

    public function get(Model|Authenticatable $record): string
    {
        $initials = htmlspecialchars($this->initials(Filament::getNameForDefaultAvatar($record)), ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64">'
            .'<rect width="64" height="64" fill="'.self::BACKGROUND.'"/>'
            .'<text x="50%" y="50%" dy=".35em" text-anchor="middle" fill="#ffffff" '
            .'font-family="system-ui, -apple-system, Segoe UI, sans-serif" font-size="26" font-weight="600">'.$initials.'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    private function initials(string $name): string
    {
        $letters = '';
        foreach (preg_split('/\s+/u', trim($name)) ?: [] as $segment) {
            $segment = (string) preg_replace('/^[^\p{L}\p{N}]+/u', '', $segment);
            if ($segment !== '') {
                $letters .= mb_strtoupper(mb_substr($segment, 0, 1));
            }
        }

        return mb_substr($letters, 0, 2) ?: '?';
    }
}
