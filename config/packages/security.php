<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\App;

return App::config([
    'security' => [
        'firewalls' => [
            'dev' => [
                'pattern' => '^/(_(profiler|wdt))',
                'security' => false,
            ],
            'main' => [
                'lazy' => true,
            ],
        ],
    ],
]);
