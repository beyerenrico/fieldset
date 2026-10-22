<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Forms\Pages;

use Illuminate\Container\Attributes\Scoped;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * One classic-builder (v1) render at a time per instance (ADR 0083 amendment 2026-10-22). One render of the 4.5 MB page
 * (ADR 0044) is what the app's memory limit is sized for: 353 of `small`'s 384 MiB. Two at once push FrankenPHP over the
 * limit, and the kernel kills it with every request it serves. EditForm takes the slot when it boots, for the page and
 * for each of its Livewire requests, and frees it when the request ends; a render waits for the slot and answers 503
 * when the wait runs out. Livewire's persistent middleware cannot do this: it runs on a stand-in request before the
 * component renders, not around the render.
 */
#[Scoped]
final class ClassicBuilderSlot
{
    public const string LOCK = 'forms:classic-builder-render';

    /** Bound for a holder that dies without releasing: far above one render, below PHP's execution limit plus the wait. */
    private const int HOLD_SECONDS = 60;

    private ?Lock $lock = null;

    public function hold(): void
    {
        if ($this->lock instanceof Lock) {
            return;
        }
        $lock = Cache::lock(self::LOCK, self::HOLD_SECONDS);
        try {
            $lock->block((int) config('forms.classic_builder.render_wait'));
        } catch (LockTimeoutException) {
            throw new HttpException(503, __('forms.errors.classic_builder_busy'), headers: ['Retry-After' => '5']);
        }
        $this->lock = $lock;
        app()->terminating($this->release(...));
    }

    public function release(): void
    {
        $this->lock?->release();
        $this->lock = null;
    }
}
