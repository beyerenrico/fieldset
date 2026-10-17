<?php

declare(strict_types=1);

namespace App\Filament\Platform;

use App\Domain\Identity\Models\User;
use App\Domain\Platform\Impersonation\Impersonation;
use App\Domain\Platform\Impersonation\ImpersonationStore;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Support\HtmlString;

/**
 * Renders the impersonation banner (Filament render hook BODY_START, every panel) while the signed-in user is
 * the impersonated target; nothing otherwise.
 */
final readonly class ImpersonationBanner
{
    public function __construct(private ImpersonationStore $store, private AuthFactory $auth) {}

    public function render(): HtmlString
    {
        $impersonation = $this->store->current();
        $target = $this->auth->guard('web')->user();
        if (! $impersonation instanceof Impersonation || ! $target instanceof User || $target->id !== $impersonation->targetId) {
            return new HtmlString('');
        }

        $operator = User::query()->find($impersonation->operatorId);

        return new HtmlString(view('filament.platform.impersonation-banner', [
            'operator' => $operator !== null ? $operator->name.' <'.$operator->email.'>' : '#'.$impersonation->operatorId,
            'target' => $target->name.' <'.$target->email.'>',
            'until' => $impersonation->expiresAt->setTimezone((string) config('app.timezone', 'UTC'))->format('H:i'),
            'minutes' => $impersonation->remainingMinutes(),
            'exitUrl' => route('filament.platform.impersonation.exit'),
        ])->render());
    }
}
