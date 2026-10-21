<?php

declare(strict_types=1);

namespace Tests\Support\Instance;

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Input\InputOption;

/**
 * What the launchers (docker/app/fieldset, distribution/shim.sh) must know about the registered fieldset:* commands to find
 * the command behind leading flags: every option that takes a value (required or optional; Symfony gives an optional
 * one the next argument too) plus install's --version alias, which the wrapper maps to --release.
 */
final class FieldsetCommandFlags
{
    /** @return list<string> sorted, with leading dashes */
    public static function valueFlags(): array
    {
        $flags = ['--version'];
        foreach (Artisan::all() as $name => $command) {
            if (! str_starts_with($name, 'fieldset:')) {
                continue;
            }
            $options = array_filter($command->getDefinition()->getOptions(), static fn (InputOption $option): bool => $option->acceptValue());
            array_push($flags, ...array_map(static fn (InputOption $option): string => '--'.$option->getName(), array_values($options)));
        }
        $flags = array_values(array_unique($flags));
        sort($flags);

        return $flags;
    }
}
