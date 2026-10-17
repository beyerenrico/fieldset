<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Domain\Audit\Models\Activity;
use App\Domain\Identity\Models\User;
use App\Domain\Privacy\Erasure\AccountScrubber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Human labels for audit entries (audit log UI, history, CSV): event, subject, actor and impersonating operator. */
final class AuditLabels
{
    private const array NAME_ATTRIBUTES = ['name', 'title'];

    private const int NAME_LENGTH = 80;

    public static function event(?string $event): string
    {
        if ($event === null || $event === '') {
            return '—';
        }
        $key = 'audit.events.'.$event;
        $label = __($key);

        return is_string($label) && $label !== $key ? $label : Str::headline(str_replace(['.', '_'], ' ', $event));
    }

    public static function subjectType(?string $type): string
    {
        if ($type === null || $type === '') {
            return '—';
        }
        $base = class_basename($type);
        $key = 'audit.subjects.'.$base;
        $label = __($key);

        return is_string($label) && $label !== $key ? $label : Str::headline($base);
    }

    /** The causer's name (an erased account shows "Deleted user", translated), "System" or "<name> (API token)". */
    public static function actor(Activity $activity): string
    {
        $causer = $activity->causer_id === null ? null : $activity->getRelationValue('causer');
        if (! $causer instanceof User) {
            return $activity->causer_id === null ? (string) __('audit.actor_types.system') : '#'.$activity->causer_id;
        }

        $name = AccountScrubber::displayName($causer->name);

        return $activity->actor_type === 'api_token' ? (string) __('audit.actor_types.api_token_of', ['name' => $name]) : $name;
    }

    /** "Form: Service report (#12)" when the subject is loaded and has a name or title, else "Form #12". */
    public static function subject(Activity $activity): string
    {
        if ($activity->subject_type === null) {
            return '—';
        }
        $type = self::subjectType($activity->subject_type);
        $name = self::subjectName($activity);

        return $name === null ? $type.' #'.$activity->subject_id : $type.': '.$name.' (#'.$activity->subject_id.')';
    }

    /** The platform operator who acted while impersonating (AuditImpersonator): email, else "#id". */
    public static function impersonator(Activity $activity): ?string
    {
        $mark = $activity->properties?->get(AuditImpersonator::PROPERTY);
        if (! is_array($mark)) {
            return null;
        }
        if (is_string($mark['email'] ?? null) && $mark['email'] !== '') {
            return $mark['email'];
        }

        return is_numeric($mark['id'] ?? null) ? '#'.$mark['id'] : null;
    }

    /** "on behalf of operator X" under the actor, or null for an entry without impersonation. */
    public static function onBehalfOf(Activity $activity): ?string
    {
        $operator = self::impersonator($activity);

        return $operator === null ? null : (string) __('audit.impersonation.via', ['operator' => $operator]);
    }

    /**
     * Name or title of the subject, only when the caller eager-loaded it (`with('subject')`): never a lazy
     * load per row. Raw attributes, so a model without such a column never trips strict mode.
     */
    private static function subjectName(Activity $activity): ?string
    {
        $subject = $activity->relationLoaded('subject') ? $activity->getRelation('subject') : null;
        if (! $subject instanceof Model) {
            return null;
        }
        $attributes = $subject->getAttributes();
        foreach (self::NAME_ATTRIBUTES as $key) {
            if (is_string($attributes[$key] ?? null) && trim($attributes[$key]) !== '') {
                $name = $subject instanceof User ? AccountScrubber::displayName(trim($attributes[$key])) : trim($attributes[$key]);

                return Str::limit($name, self::NAME_LENGTH);
            }
        }

        return null;
    }
}
