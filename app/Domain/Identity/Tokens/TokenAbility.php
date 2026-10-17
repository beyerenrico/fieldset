<?php

declare(strict_types=1);

namespace App\Domain\Identity\Tokens;

/**
 * Abilities of an integration token (ADR 0043), one per API resource and access kind. routes/api.php names
 * the abilities each route needs; the role permissions of the token's user are checked on top, as for the
 * Field App, so a token can never do more than its user.
 */
enum TokenAbility: string
{
    case FormsRead = 'forms:read';
    case SubmissionsRead = 'submissions:read';
    case SubmissionsWrite = 'submissions:write';
    case DocumentsRead = 'documents:read';
    case TasksRead = 'tasks:read';
    case TasksWrite = 'tasks:write';
    case NotificationsRead = 'notifications:read';

    public function label(): string
    {
        return __('api_tokens.abilities.'.$this->key().'.label');
    }

    public function description(): string
    {
        return __('api_tokens.abilities.'.$this->key().'.description');
    }

    /** @return list<string> in catalog order */
    public static function values(): array
    {
        return array_map(static fn (self $ability): string => $ability->value, self::cases());
    }

    /**
     * Known abilities of the list in catalog order, without duplicates.
     *
     * @param  array<array-key, mixed>  $abilities
     * @return list<string>
     */
    public static function normalize(array $abilities): array
    {
        return array_values(array_filter(self::values(), static fn (string $value): bool => in_array($value, $abilities, true)));
    }

    /** @return array<string, string> value => label, for checkbox lists */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $ability) {
            $options[$ability->value] = $ability->label();
        }

        return $options;
    }

    private function key(): string
    {
        return str_replace(':', '_', $this->value);
    }
}
