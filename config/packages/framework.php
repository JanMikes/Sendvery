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
        // TASK-159: per-IP rate-limit for the founder contact form. Five
        // submissions/hour is generous for legitimate humans (a person
        // typing slowly takes minutes per message, not seconds) but
        // forecloses scripted-form-fill abuse. Autowires as
        // RateLimiterFactory $contactFormLimiter (camelCase + Limiter).
        // NO 3rd-party CAPTCHA — defence is layered: honeypot field +
        // time-trap + this rate-limiter, all in-house.
        'rate_limiter' => [
            'contact_form' => [
                'policy' => 'token_bucket',
                'limit' => 5,
                'rate' => ['interval' => '1 hour', 'amount' => 5],
            ],
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
            // TASK-159: rate-limiter token-bucket state needs to survive
            // services_resetter->reset() so a single test method can verify
            // that 5 submissions exhaust the bucket and the 6th is blocked.
            // Symfony's default cache.adapter.array is tagged kernel.reset
            // and wipes itself between requests, making rate-limit verification
            // impossible. Filesystem pool persists between requests within a
            // test; each test method's createClient() boots a fresh kernel
            // in a fresh cache namespace so leakage between tests stays nil.
            'cache' => [
                'pools' => [
                    'cache.rate_limiter' => [
                        'adapter' => 'cache.adapter.filesystem',
                        'public' => true,
                    ],
                ],
            ],
        ],
    ],
]);
