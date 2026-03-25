<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return function (RoutingConfigurator $routes): void {
    if (in_array($routes->env(), ['dev', 'test'], true)) {
        $routes->import('@WebProfilerBundle/Resources/config/routing/wdt.php')
            ->prefix('/_wdt');
        $routes->import('@WebProfilerBundle/Resources/config/routing/profiler.php')
            ->prefix('/_profiler');
    }
};
