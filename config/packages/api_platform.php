<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\App;

return App::config([
    'api_platform' => [
        'title' => 'Sendvery API',
        'version' => '1.0.0',
        'defaults' => [
            'stateless' => true,
            'cache_headers' => [
                'vary' => ['Content-Type', 'Authorization', 'Origin'],
            ],
        ],
    ],
]);
