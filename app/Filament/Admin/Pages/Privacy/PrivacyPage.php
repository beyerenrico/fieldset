<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Privacy;

use App\Domain\Identity\Models\User;
use Closure;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/** Shared helpers of the privacy pages: the signed-in user and how domain errors are shown. */
final class PrivacyPage
{
    public static function user(): User
    {
        $user = Filament::auth()->user();

        return $user instanceof User ? $user : throw new AuthorizationException;
    }

    /**
     * Runs a domain action of a confirmation without inputs; validation and authorization failures become a
     * warning notification.
     */
    public static function attempt(Closure $action, string $success): bool
    {
        try {
            return self::run($action, $success);
        } catch (ValidationException $e) {
            Notification::make()->warning()->title((string) collect($e->errors())->flatten()->first())->send();

            return false;
        }
    }

    /**
     * Runs a domain action of a modal with inputs (§9.1: errors next to the field). Validation errors are
     * rethrown under the modal's state path, so the modal stays open with the input kept: an error about one
     * of $fields shows at that field, any other at the first one. Authorization failures are a notification.
     *
     * @param  non-empty-list<string>  $fields
     */
    public static function attemptInForm(Closure $action, string $success, Schema $schema, array $fields): bool
    {
        try {
            return self::run($action, $success);
        } catch (ValidationException $e) {
            throw self::underForm($e, $schema, $fields);
        }
    }

    /** @param  non-empty-list<string>  $fields */
    public static function underForm(ValidationException $e, Schema $schema, array $fields): ValidationException
    {
        $messages = [];
        foreach ($e->errors() as $key => $errors) {
            $field = in_array($key, $fields, true) ? $key : $fields[0];
            $path = $schema->getStatePath().'.'.$field;
            $messages[$path] = [...($messages[$path] ?? []), ...$errors];
        }

        return ValidationException::withMessages($messages);
    }

    /** @throws ValidationException */
    private static function run(Closure $action, string $success): bool
    {
        try {
            $action();
        } catch (AuthorizationException) {
            Notification::make()->danger()->title(__('privacy.not_allowed'))->send();

            return false;
        }
        Notification::make()->success()->title($success)->send();

        return true;
    }
}
