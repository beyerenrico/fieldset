<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Domain\Audit\Models\Activity;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Str;

/** Human labels for audit entries (audit log UI, history, CSV): event, subject type and actor. */
final class AuditLabels
{
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

    /** The causer's name (an erased account shows its anonymized name), "System" or "<name> (API token)". */
    public static function actor(Activity $activity): string
    {
        $causer = $activity->causer_id === null ? null : $activity->getRelationValue('causer');
        if (! $causer instanceof User) {
            return $activity->causer_id === null ? (string) __('audit.actor_types.system') : '#'.$activity->causer_id;
        }

        return $activity->actor_type === 'api_token' ? (string) __('audit.actor_types.api_token_of', ['name' => $causer->name]) : $causer->name;
    }

    public static function subject(Activity $activity): string
    {
        return $activity->subject_type === null ? '—' : self::subjectType($activity->subject_type).' #'.$activity->subject_id;
    }
}
