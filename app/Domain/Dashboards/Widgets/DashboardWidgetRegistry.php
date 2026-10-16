<?php

declare(strict_types=1);

namespace App\Domain\Dashboards\Widgets;

use App\Domain\Dashboards\DashboardContext;
use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;
use InvalidArgumentException;

/** Collects the widgets of all configured DashboardWidgetSources for a viewer. */
final class DashboardWidgetRegistry
{
    /** @return list<class-string<Widget>|WidgetConfiguration> */
    public function widgetsFor(DashboardContext $context): array
    {
        $widgets = [];
        $seen = [];
        foreach ($this->sources() as $source) {
            foreach ($source->widgetsFor($context) as $widget) {
                if (is_string($widget)) {
                    if (isset($seen[$widget])) {
                        continue;
                    }
                    $seen[$widget] = true;
                }
                $widgets[] = $widget;
            }
        }

        return $widgets;
    }

    /** @param  class-string<Widget>  $widget */
    public function offers(DashboardContext $context, string $widget): bool
    {
        return array_any($this->widgetsFor($context), fn (string|WidgetConfiguration $offered): bool => ($offered instanceof WidgetConfiguration ? $offered->widget : $offered) === $widget);
    }

    /** @return list<DashboardWidgetSource> */
    private function sources(): array
    {
        $sources = [];
        foreach ((array) config('dashboards.widget_sources', []) as $class) {
            $source = is_string($class) ? app($class) : null;
            if (! $source instanceof DashboardWidgetSource) {
                throw new InvalidArgumentException('dashboards.widget_sources entries must implement '.DashboardWidgetSource::class.'.');
            }
            $sources[] = $source;
        }

        return $sources;
    }
}
