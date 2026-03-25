<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\App;

return App::config([
    'when@test' => [
        'framework' => [
            'validation' => [
                'not_compromised_password' => false,
            ],
        ],
    ],
]);
