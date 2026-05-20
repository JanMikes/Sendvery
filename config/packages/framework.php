<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\App;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler;

return App::config([
    'framework' => [
        'secret' => '%env(APP_SECRET)%',
        'http_method_override' => false,
        'handle_all_throwables' => true,
        'php_errors' => [
            'log' => true,
        ],
        // Sessions are stored in Postgres via PdoSessionHandler so they
        // survive container restarts. A dedicated PDO connection (built
        // from DATABASE_URL) is used instead of the Doctrine connection:
        // PdoSessionHandler holds a SELECT ... FOR UPDATE transaction
        // for the whole request, which would collide with Doctrine writes
        // and with DAMA DoctrineTestBundle's per-test rollback transaction.
        'session' => [
            'handler_id' => PdoSessionHandler::class,
            'cookie_secure' => 'auto',
            'cookie_samesite' => 'lax',
            'storage_factory_id' => 'session.storage.factory.native',
        ],
        'property_info' => [
            'with_constructor_extractor' => true,
        ],
        'csrf_protection' => [
            'check_header' => true,
        ],
    ],
    'when@test' => [
        'framework' => [
            'test' => true,
            // Tests use file-based sessions so per-test session rows
            // don't escape DAMA DoctrineTestBundle's rollback.
            'session' => [
                'handler_id' => null,
            ],
        ],
    ],
]);
