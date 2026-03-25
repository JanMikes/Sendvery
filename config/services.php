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
        'App\Services\Mail\MailClient' => [
            'alias' => 'App\Services\Mail\ImapMailClient',
        ],
        'App\Services\Stripe\SubscriptionManager' => [
            'arguments' => [
                '$defaultUri' => '%env(DEFAULT_URI)%',
            ],
        ],
        'App\Controller\Webhook\StripeWebhookController' => [
            'arguments' => [
                '$stripeWebhookSecret' => '%env(STRIPE_WEBHOOK_SECRET)%',
            ],
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
            'App\Repository\BetaSignupRepository' => [
                'public' => true,
            ],
            'App\MessageHandler\RegisterBetaSignupHandler' => [
                'public' => true,
            ],
            'App\MessageHandler\ProcessDmarcReportHandler' => [
                'public' => true,
            ],
            'App\Repository\MonitoredDomainRepository' => [
                'public' => true,
            ],
            'App\Repository\DmarcReportRepository' => [
                'public' => true,
            ],
            'App\Query\GetDomainOverview' => [
                'public' => true,
            ],
            'App\Query\GetDomainReports' => [
                'public' => true,
            ],
            'App\Query\GetReportDetail' => [
                'public' => true,
            ],
            'App\Services\Dmarc\DmarcXmlParser' => [
                'public' => true,
            ],
            'App\Services\Dmarc\ReportAttachmentExtractor' => [
                'public' => true,
            ],
            'App\Services\CredentialEncryptor' => [
                'public' => true,
            ],
            'App\Repository\MailboxConnectionRepository' => [
                'public' => true,
            ],
            'App\MessageHandler\ConnectMailboxHandler' => [
                'public' => true,
            ],
            'App\MessageHandler\PollMailboxHandler' => [
                'public' => true,
            ],
            'App\Services\Mail\FakeMailClient' => [
                'public' => true,
            ],
            'App\Services\Mail\MailClient' => [
                'alias' => 'App\Services\Mail\FakeMailClient',
                'public' => true,
            ],
            'App\Query\GetDashboardStats' => [
                'public' => true,
            ],
            'App\Query\GetDomainDetail' => [
                'public' => true,
            ],
            'App\Query\GetDomainSenderBreakdown' => [
                'public' => true,
            ],
            'App\Query\GetDomainPassRateTrend' => [
                'public' => true,
            ],
            'App\Query\GetAllReports' => [
                'public' => true,
            ],
            'App\Services\DashboardContext' => [
                'public' => true,
            ],
            'App\MessageHandler\AddDomainHandler' => [
                'public' => true,
            ],
            'App\Repository\MagicLinkTokenRepository' => [
                'public' => true,
            ],
            'App\MessageHandler\RequestMagicLinkHandler' => [
                'public' => true,
            ],
            'App\Services\OnboardingTracker' => [
                'public' => true,
            ],
            'App\Security\MagicLinkAuthenticator' => [
                'public' => true,
            ],
            'App\Services\Stripe\PlanEnforcement' => [
                'public' => true,
            ],
            'App\Services\Stripe\PlanLimits' => [
                'public' => true,
            ],
            'App\MessageHandler\UpgradeTeamPlanHandler' => [
                'public' => true,
            ],
            'App\MessageHandler\DowngradeTeamPlanHandler' => [
                'public' => true,
            ],
            'App\Query\GetBillingOverview' => [
                'public' => true,
            ],
            'App\Query\GetTeamPlan' => [
                'public' => true,
            ],
            'App\Services\SenderDiscovery' => [
                'public' => true,
            ],
            'App\Services\OrganizationMapper' => [
                'public' => true,
            ],
            'App\Services\BlacklistChecker' => [
                'public' => true,
            ],
            'App\Services\PdfReportGenerator' => [
                'public' => true,
            ],
            'App\Repository\KnownSenderRepository' => [
                'public' => true,
            ],
            'App\Query\GetSenderInventory' => [
                'public' => true,
            ],
            'App\Query\GetBlacklistStatus' => [
                'public' => true,
            ],
            'App\Query\GetDomainHealthHistory' => [
                'public' => true,
            ],
            'App\Query\GetDomainReportData' => [
                'public' => true,
            ],
            'App\MessageHandler\UpdateSenderInventoryOnReport' => [
                'public' => true,
            ],
            'App\MessageHandler\CheckBlacklistHandler' => [
                'public' => true,
            ],
            'App\MessageHandler\MarkSenderAuthorizedHandler' => [
                'public' => true,
            ],
        ],
    ],
]);
