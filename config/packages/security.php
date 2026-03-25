<?php

declare(strict_types=1);

use App\Security\MagicLinkAuthenticator;
use Symfony\Component\DependencyInjection\Loader\Configurator\App;

return App::config([
    'security' => [
        'providers' => [
            'app_user_provider' => [
                'entity' => [
                    'class' => 'App\Entity\User',
                    'property' => 'email',
                ],
            ],
        ],
        'firewalls' => [
            'dev' => [
                'pattern' => '^/(_(profiler|wdt))',
                'security' => false,
            ],
            'main' => [
                'lazy' => true,
                'provider' => 'app_user_provider',
                'entry_point' => MagicLinkAuthenticator::class,
                'custom_authenticators' => [
                    MagicLinkAuthenticator::class,
                ],
                'logout' => [
                    'path' => 'auth_logout',
                    'target' => 'home',
                ],
                'remember_me' => [
                    'secret' => '%kernel.secret%',
                    'lifetime' => 2592000, // 30 days
                    'path' => '/',
                    'always_remember_me' => true,
                ],
            ],
        ],
        'access_control' => [
            ['path' => '^/app', 'roles' => 'ROLE_USER'],
        ],
    ],
]);
