<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Identity\Support\LocaleResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class SetLocale
{
    public function __construct(private LocaleResolver $locales) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->locales->apply($request);

        return $next($request);
    }
}
