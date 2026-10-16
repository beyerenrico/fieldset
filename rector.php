<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use RectorLaravel\Set\LaravelSetList;

return RectorConfig::configure()
    ->withPaths([__DIR__.'/app', __DIR__.'/database', __DIR__.'/routes', __DIR__.'/tests'])
    ->withPhpSets(php84: true)
    ->withSets([LaravelSetList::LARAVEL_130])
    ->withPreparedSets(deadCode: true, codeQuality: true, typeDeclarations: true);
