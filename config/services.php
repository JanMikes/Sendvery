<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\App;

return App::config([
    'services' => [
        'App\\' => [
            'resource' => '../src/',
            'exclude' => [
                '../src/DependencyInjection/',
                '../src/Entity/',
                '../src/Kernel.php',
            ],
        ],
        'Spatie\Dns\Dns' => [
            'autoconfigure' => true,
        ],
        'SPFLib\Decoder' => [
            'autoconfigure' => true,
        ],
        'SPFLib\SemanticValidator' => [
            'autoconfigure' => true,
        ],
    ],
    'when@test' => [
        'services' => [
            'App\Services\IdentityProvider' => [
                'public' => true,
            ],
            'App\Services\TeamContext' => [
                'public' => true,
            ],
            'App\Repository\TeamRepository' => [
                'public' => true,
            ],
            'App\Repository\UserRepository' => [
                'public' => true,
            ],
            'App\Repository\TeamMembershipRepository' => [
                'public' => true,
            ],
            'App\Query\GetUserTeams' => [
                'public' => true,
            ],
            'App\Services\Dns\SpfChecker' => [
                'public' => true,
            ],
            'App\Services\Dns\DkimChecker' => [
                'public' => true,
            ],
            'App\Services\Dns\DmarcChecker' => [
                'public' => true,
            ],
            'App\Services\Dns\MxChecker' => [
                'public' => true,
            ],
            'App\Services\Dns\EmailAuthChecker' => [
                'public' => true,
            ],
            'App\Services\Dns\DomainHealthScorer' => [
                'public' => true,
            ],
        ],
    ],
]);
