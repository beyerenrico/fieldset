<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Delivery;

use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\LocaleResolver;
use App\Domain\Mail\Templates\TokenReplacer;
use App\Domain\Notifications\NotificationMessage;

/**
 * Renders a locale-neutral NotificationMessage for one recipient (§8.9, §11): translation keys in the
 * user's locale, else the organization's, else the app default. Parameters are inserted as plain
 * text in one pass; channels escape the result on output (bell: HTML entities, mail: Markdown + HTML).
 */
final class NotificationRenderer
{
    public function render(NotificationMessage $message, User $user, ?Organization $organization): RenderedNotification
    {
        $locale = $this->locale($user, $organization);
        $params = array_map(static fn (string|int|float|null $value): string => (string) $value, $message->params);

        return new RenderedNotification(
            title: TokenReplacer::singleLine($this->translate($message->titleKey, $params, $locale)),
            body: trim($this->translate($message->bodyKey, $params, $locale)),
            locale: $locale,
            url: self::safeUrl($message->url),
        );
    }

    public function locale(User $user, ?Organization $organization): string
    {
        foreach ([$user->locale, $organization?->locale] as $candidate) {
            if (in_array($candidate, LocaleResolver::SUPPORTED, true)) {
                return $candidate;
            }
        }

        return (string) config('app.locale', 'en');
    }

    /** Only same-site paths and http(s) URLs; anything else (javascript:, //host, data:) is dropped. */
    public static function safeUrl(?string $url): ?string
    {
        $url = $url === null ? '' : trim($url);
        if ($url === '' || preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            return null;
        }
        if (str_starts_with($url, '/')) {
            return str_starts_with($url, '//') || str_starts_with($url, '/\\') ? null : $url;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) && filter_var($url, FILTER_VALIDATE_URL) !== false ? $url : null;
    }

    /** @param  array<string, string>  $params */
    private function translate(string $key, array $params, string $locale): string
    {
        $line = trans($key, $params, $locale);

        return is_string($line) ? $line : $key;
    }
}
