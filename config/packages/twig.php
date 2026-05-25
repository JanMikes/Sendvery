<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\App;

return App::config([
    'twig' => [
        'default_path' => '%kernel.project_dir%/templates',
        'globals' => [
            'google_analytics_id' => '%env(GOOGLE_ANALYTICS_ID)%',
        ],
    ],
]);
