<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\App;

return App::config([
    'framework' => [
        'messenger' => [
            'buses' => [
                'command_bus' => [
                    'middleware' => [
                        'doctrine_transaction',
                    ],
                ],
            ],
            'failure_transport' => 'failed',
            'transports' => [
                'sync' => ['dsn' => 'sync://'],
                'failed' => ['dsn' => 'doctrine://default?queue_name=failed'],
                'async' => ['dsn' => '%env(MESSENGER_TRANSPORT_DSN)%'],
            ],
            'routing' => [],
        ],
    ],
]);
