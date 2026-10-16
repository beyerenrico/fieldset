<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPublicMethodParameterRector;
use Rector\Php80\Rector\Ternary\TernaryToNullsafeCoalesceRector;
use Rector\TypeDeclaration\Rector\ArrowFunction\AddArrowFunctionReturnTypeRector;
use RectorLaravel\Set\LaravelSetList;

return RectorConfig::configure()
    ->withPaths([__DIR__.'/app', __DIR__.'/database', __DIR__.'/routes', __DIR__.'/tests'])
    ->withPhpSets(php84: true)
    ->withSets([LaravelSetList::LARAVEL_130])
    ->withPreparedSets(deadCode: true, codeQuality: true, typeDeclarations: true)
    // Policy abilities receive (user, model) positionally from the Gate, even when a check ignores one.
    ->withSkip([
        RemoveUnusedPublicMethodParameterRector::class => [__DIR__.'/app/Policies', __DIR__.'/app/Domain/*/Policies'],
        // Conflicts with PHPStan's nullsafe.neverNull (`$a?->b ?? $c` is flagged as unnecessary).
        TernaryToNullsafeCoalesceRector::class,
        // Infers Pest's mixin class for expect() chains in tests, which fails at runtime.
        AddArrowFunctionReturnTypeRector::class => [__DIR__.'/tests'],
    ]);
