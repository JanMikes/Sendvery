<?php

declare(strict_types=1);

use App\Services\Sentry\GenericObjectSerializer;
use Symfony\Component\DependencyInjection\Loader\Configurator\App;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

// Auto-discover FormData and Message classes and wire them to GenericObjectSerializer.
// This turns Sentry's default opaque object dumps into readable property maps
// (UUIDs as strings, enums as names, dates formatted, secrets redacted).
$projectDir = dirname(__DIR__, 2);
$classSerializers = [];

$serializableNamespaces = [
    'App\\FormData\\' => $projectDir.'/src/FormData/',
    'App\\Message\\' => $projectDir.'/src/Message/',
];

foreach ($serializableNamespaces as $namespace => $directory) {
    foreach (glob($directory.'*.php') ?: [] as $file) {
        $classSerializers[$namespace.basename($file, '.php')] = GenericObjectSerializer::class;
    }
}

return App::config([
    'sentry' => [
        'dsn' => '%env(SENTRY_DSN)%',

        // Bundle defaults (register_error_listener / register_error_handler) are kept enabled:
        // dmarc does not use a Monolog Sentry handler, so the kernel exception listener is what
        // captures unhandled errors.

        'options' => [
            'environment' => '%kernel.environment%',
            'release' => '%env(default::SENTRY_RELEASE)%',
            'send_default_pii' => true,
            'attach_stacktrace' => true,
            'max_breadcrumbs' => 50,

            'in_app_include' => ['%kernel.project_dir%/src'],
            'in_app_exclude' => [
                '%kernel.cache_dir%',
                '%kernel.project_dir%/vendor',
            ],

            // Noise filters: don't ship 403s and 404s, they're not actionable.
            'ignore_exceptions' => [
                AccessDeniedException::class,
                NotFoundHttpException::class,
            ],

            // Skip Symfony web profiler / debug toolbar routes (and a couple of infra checks).
            'ignore_transactions' => [
                '*/_wdt*',
                '*/_profiler*',
                '*/health',
            ],

            // Traces are gated by the sampler — default 0 / on-demand profiling via secret.
            // See App\Services\Sentry\SentryTracesSampler.
            'traces_sampler' => 'sentry.traces_sampler',
            // 1.0 here is relative to traces_sample_rate, so we profile every sampled trace.
            'profiles_sample_rate' => 1.0,

            'class_serializers' => $classSerializers,
        ],

        'messenger' => [
            'enabled' => true,
            // Report failures even when the message will be retried — better to over-report
            // than miss a permanently-failing handler.
            'capture_soft_fails' => true,
            // Critical for long-running messenger workers: prevents breadcrumb leakage
            // between consumed messages in the same PHP process.
            'isolate_breadcrumbs_by_message' => true,
        ],

        'tracing' => [
            'enabled' => true,
            'dbal' => [
                'enabled' => true,
            ],
            'cache' => ['enabled' => true],
            'http_client' => ['enabled' => true],
            'twig' => ['enabled' => true],
            'console' => [
                // `messenger:consume` is a long-running command — a single transaction per command
                // would span the entire worker lifetime. Per-message tracing is handled by the
                // messenger integration above.
                'excluded_commands' => [
                    'messenger:consume',
                ],
            ],
        ],
    ],
]);
