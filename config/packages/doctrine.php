<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\App;

return App::config([
    'doctrine' => [
        'dbal' => [
            'url' => '%env(resolve:DATABASE_URL)%',
            'types' => [
                'uuid' => 'Ramsey\Uuid\Doctrine\UuidType',
            ],
        ],
        'orm' => [
            'naming_strategy' => 'doctrine.orm.naming_strategy.underscore_number_aware',
            'auto_mapping' => true,
            'mappings' => [
                'App' => [
                    'type' => 'attribute',
                    'is_bundle' => false,
                    'dir' => '%kernel.project_dir%/src/Entity',
                    'prefix' => 'App\Entity',
                    'alias' => 'App',
                ],
            ],
            'filters' => [
                'team_filter' => [
                    'class' => 'App\Doctrine\TeamFilter',
                    'enabled' => true,
                ],
            ],
        ],
    ],
]);
