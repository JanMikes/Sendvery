<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\App;

return App::config([
    'when@prod' => [
        'framework' => [
            'router' => [
                'strict_requirements' => null,
            ],
        ],
    ],
]);
