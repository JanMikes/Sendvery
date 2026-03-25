<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\App;

return App::config([
    'services' => [
        'App\\' => [
            'resource' => '../src/',
            'exclude' => [
                '../src/DependencyInjection/',
                '../src/Entity/',
                '../src/Kernel.php',
            ],
        ],
    ],
    'when@test' => [
        'services' => [
            'App\Services\IdentityProvider' => [
                'public' => true,
            ],
        ],
    ],
]);
