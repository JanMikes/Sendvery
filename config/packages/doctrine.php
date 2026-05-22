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
        // Team scoping is enforced explicitly in every Query class / repo
        // method that touches tenant data (see DashboardContext::getTeamIds()
        // — passed into queries as a `team_id IN (...)` clause). We do NOT
        // use a Doctrine SQL filter: it only covers ORM queries (silently
        // skipping raw DBAL reads, which is most of our read side), and it
        // makes the security check invisible at the call site.
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
        ],
    ],
]);
