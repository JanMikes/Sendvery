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
                'event_bus' => [
                    'default_middleware' => [
                        'allow_no_handlers' => true,
                    ],
                    'middleware' => [
                        'doctrine_transaction',
                    ],
                ],
            ],
            'default_bus' => 'command_bus',
            'failure_transport' => 'failed',
            'transports' => [
                'sync' => ['dsn' => 'sync://'],
                'failed' => ['dsn' => 'doctrine://default?queue_name=failed'],
                'async' => ['dsn' => '%env(MESSENGER_TRANSPORT_DSN)%'],
            ],
            'routing' => [
                // Decouple the Anthropic call for anomaly insights from report
                // ingestion. The prod `worker` container already consumes `async`
                // (Doctrine transport); a slow/failing API call can't roll back
                // the parse, and exhausted retries land in `failed`.
                \App\Message\GenerateAnomalyInsight::class => 'async',
                \App\Message\GenerateRemediationInsight::class => 'async',
            ],
        ],
    ],
]);
