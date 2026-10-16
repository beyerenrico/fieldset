<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Domain\Forms\Events\FormPublished;
use App\Domain\Notifications\Listeners\NotifyFormAvailable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Notifications wiring (§8.9): the Notifier binding and the listeners for events that are not part
 * of the workflow engine ("new form available"). Registered from AppServiceProvider.
 */
final class NotificationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(Notifier::class, QueuedNotifier::class);
    }

    public function boot(): void
    {
        Event::listen(FormPublished::class, NotifyFormAvailable::class);
    }
}
