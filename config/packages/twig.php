<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\App;

return App::config([
    'twig' => [
        'default_path' => '%kernel.project_dir%/templates',
        'globals' => [
            // Cutover knob (DEC-050 → Phase 6 of pricing implementation
            // plan). When false, pricing-page CTAs route to request_beta_access;
            // when true, they go to dashboard_billing_upgrade. Flip in prod
            // once Stripe products + 12 prices exist + live env vars are set.
            'stripe_live' => '%env(bool:SENDVERY_STRIPE_LIVE)%',
        ],
    ],
]);
