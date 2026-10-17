<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Privacy;

use App\Domain\Identity\Models\User;
use Closure;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/** Shared helpers of the privacy pages: the signed-in user and domain errors shown as notifications. */
final class PrivacyPage
{
    public static function user(): User
    {
        $user = Filament::auth()->user();

        return $user instanceof User ? $user : throw new AuthorizationException;
    }

    /** Runs a domain action; validation and authorization failures become a warning notification. */
    public static function attempt(Closure $action, string $success): bool
    {
        try {
            $action();
        } catch (ValidationException $e) {
            Notification::make()->warning()->title((string) collect($e->errors())->flatten()->first())->send();

            return false;
        } catch (AuthorizationException) {
            Notification::make()->danger()->title(__('privacy.not_allowed'))->send();

            return false;
        }
        Notification::make()->success()->title($success)->send();

        return true;
    }
}
