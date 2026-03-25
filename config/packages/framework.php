<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\App;

return App::config([
    'framework' => [
        'secret' => '%env(APP_SECRET)%',
        'http_method_override' => false,
        'handle_all_throwables' => true,
        'php_errors' => [
            'log' => true,
        ],
        'session' => [
            'handler_id' => null,
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
        ],
    ],
]);
