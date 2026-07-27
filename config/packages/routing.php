<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\App;

return App::config([
    'framework' => [
        'router' => [
            // Absolute URLs generated outside an HTTP request — every email
            // link, since all mail is produced by cron/messenger handlers —
            // come from this base URI. Without it Symfony's RequestContext
            // falls back to its built-in `http://localhost/` default, which is
            // exactly why the weekly digest's "View full dashboard" button
            // pointed at localhost in production.
            //
            // DEFAULT_URI is the app's single public base URL (already used for
            // Stripe checkout/portal return URLs), so mail links and payment
            // redirects can never disagree about which host we live on.
            // Production MUST set it to https://sendvery.com.
            'default_uri' => '%env(DEFAULT_URI)%',
        ],
    ],
    'when@prod' => [
        'framework' => [
            'router' => [
                'strict_requirements' => null,
            ],
        ],
    ],
]);
