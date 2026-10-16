<?php

declare(strict_types=1);

namespace App\Domain\Identity\Support;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use Illuminate\Http\Request;

/**
 * UI locale: user setting > organization setting > Accept-Language > app default (D7, §11).
 */
final readonly class LocaleResolver
{
    public const array SUPPORTED = ['de', 'en'];

    public function __construct(private TenantContext $tenant) {}

    public function resolve(?Request $request = null): string
    {
        $user = $request?->user();

        if ($user instanceof User && in_array($user->locale, self::SUPPORTED, true)) {
            return (string) $user->locale;
        }

        $orgLocale = $this->tenant->organization()?->locale;
        if (in_array($orgLocale, self::SUPPORTED, true)) {
            return (string) $orgLocale;
        }

        $preferred = $request?->getPreferredLanguage(self::SUPPORTED);

        return is_string($preferred) ? $preferred : (string) config('app.locale', 'en');
    }

    public function apply(?Request $request = null): void
    {
        app()->setLocale($this->resolve($request));
    }
}
