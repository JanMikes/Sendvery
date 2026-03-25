<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\App;

return App::config([
    'services' => [
        'Stripe\StripeClient' => [
            'arguments' => [
                '%env(STRIPE_SECRET_KEY)%',
            ],
        ],
    ],
]);
