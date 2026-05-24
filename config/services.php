<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\App;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler;

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
        PdoSessionHandler::class => [
            'arguments' => ['%env(DATABASE_URL)%'],
        ],
        'Spatie\Dns\Dns' => [
            'autoconfigure' => true,
        ],
        'App\Services\Dns\SmtpProbe' => [
            'alias' => 'App\Services\Dns\SocketSmtpProbe',
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
        'App\Services\Mailbox\MailboxConnectionTester' => [
            'alias' => 'App\Services\Mailbox\ImapMailboxConnectionTester',
        ],
        'App\Services\Reports\CentralInboxClient' => [
            'alias' => 'App\Services\Reports\ImapCentralInboxClient',
        ],
        'App\Services\Github\GithubApiClient' => [
            'alias' => 'App\Services\Github\FileGetContentsGithubApiClient',
        ],
        'App\Services\Stripe\SubscriptionManager' => [
            'arguments' => [
                '$defaultUri' => '%env(DEFAULT_URI)%',
            ],
        ],
        'App\Services\Stripe\StripePriceResolver' => [
            'arguments' => [
                // DEC-057: AI variants are gated on the presence of an
                // ANTHROPIC_API_KEY. When the key is set the real AI service
                // is wired and AI plans become purchasable; when it's not,
                // StripePriceResolver throws AiNotYetPurchasable on AI plan
                // lookups (caught by UpgradePlanController).
                '$aiPurchasable' => '%env(bool:ANTHROPIC_API_KEY)%',
            ],
        ],
        // AI Insights wiring (DEC-057): the interface resolves to the
        // PlanGatedAiInsightsService decorator, which wraps the stub. When
        // real AI lands, only the $inner binding swaps to
        // AnthropicAiInsightsService — gating and quota plumbing stay put.
        'App\Services\Ai\AiInsightsService' => [
            'alias' => 'App\Services\Ai\PlanGatedAiInsightsService',
        ],
        'App\Services\Ai\PlanGatedAiInsightsService' => [
            'arguments' => [
                '$inner' => '@App\Services\Ai\StubAiInsightsService',
            ],
        ],
        'App\Controller\Webhook\StripeWebhookController' => [
            'arguments' => [
                '$stripeWebhookSecret' => '%env(STRIPE_WEBHOOK_SECRET)%',
            ],
        ],
        'App\Services\Sentry\SentryTracesSampler' => [
            'arguments' => [
                '$profilingSecret' => '%env(SENTRY_PROFILING_SECRET)%',
                '$defaultTracesSampleRate' => '%env(float:SENTRY_TRACES_SAMPLE_RATE)%',
            ],
        ],
        'sentry.traces_sampler' => [
            'class' => Closure::class,
            'factory' => ['@App\Services\Sentry\SentryTracesSampler', '__invoke'],
        ],
    ],
    'when@test' => [
        'services' => [
            // No live DNS in tests: the four DNS checkers ask Spatie\Dns\Dns,
            // which by default queries the system resolver. Aliasing it to a
            // do-nothing fake makes integration tests fast and deterministic.
            // Tests that need positive DNS data use the StubDns helper directly.
            'Spatie\Dns\Dns' => [
                'alias' => 'App\Services\Dns\FakeDns',
            ],
            'App\Services\Dns\FakeDns' => [
                'public' => true,
            ],
            // SmtpProbe: production opens a real TCP connection to port 25.
            // Tests must never do that — alias to the in-memory fake.
            'App\Services\Dns\SmtpProbe' => [
                'alias' => 'App\Services\Dns\FakeSmtpProbe',
            ],
            'App\Services\Dns\FakeSmtpProbe' => [
                'public' => true,
            ],
            // SPFLib uses its own DNS resolver, outside the App namespace and
            // outside symfony/phpunit-bridge's dns-mock reach. Inject our fake
            // resolver into the Decoder so SPF lookups stay in-process.
            'SPFLib\Decoder' => [
                'arguments' => [
                    '$dnsResolver' => '@App\Services\Dns\FakeSpfResolver',
                ],
            ],
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
            'App\MessageHandler\NotifyMeAboutToolHandler' => [
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
            'App\Services\Mail\ImapMailClient' => [
                'public' => true,
            ],
            'App\Services\Mailbox\FakeMailboxConnectionTester' => [
                'public' => true,
            ],
            'App\Services\Mailbox\ImapMailboxConnectionTester' => [
                'public' => true,
            ],
            'App\Services\Mailbox\MailboxConnectionTester' => [
                'alias' => 'App\Services\Mailbox\FakeMailboxConnectionTester',
                'public' => true,
            ],
            'App\Services\Reports\FakeCentralInboxClient' => [
                'public' => true,
            ],
            'App\Services\Reports\CentralInboxClient' => [
                'alias' => 'App\Services\Reports\FakeCentralInboxClient',
                'public' => true,
            ],
            'App\Services\Reports\CentralInboxConfig' => [
                'public' => true,
            ],
            'App\Services\Reports\ReportEmailIngestor' => [
                'public' => true,
            ],
            'App\Repository\ReceivedReportEmailRepository' => [
                'public' => true,
            ],
            'App\MessageHandler\PollReportsInboxHandler' => [
                'public' => true,
            ],
            'App\MessageHandler\ProcessReceivedReportEmailHandler' => [
                'public' => true,
            ],
            'App\MessageHandler\ReleaseQuarantinedReportsForDomainHandler' => [
                'public' => true,
            ],
            'App\MessageHandler\ReleaseQuarantinedReportsWhenDomainVerified' => [
                'public' => true,
            ],
            'App\Services\Reports\DmarcReportRouter' => [
                'public' => true,
            ],
            'App\Services\Reports\RawEmailMimeParser' => [
                'public' => true,
            ],
            'App\Repository\QuarantinedDmarcReportRepository' => [
                'public' => true,
            ],
            'App\Repository\TeamInvitationRepository' => [
                'public' => true,
            ],
            'App\MessageHandler\InviteTeammateHandler' => [
                'public' => true,
            ],
            'App\MessageHandler\AcceptTeamInvitationHandler' => [
                'public' => true,
            ],
            'App\MessageHandler\RevokeTeamInvitationHandler' => [
                'public' => true,
            ],
            'App\MessageHandler\ResendTeamInvitationHandler' => [
                'public' => true,
            ],
            'App\MessageHandler\RemoveTeamMemberHandler' => [
                'public' => true,
            ],
            'App\MessageHandler\TransferTeamOwnershipHandler' => [
                'public' => true,
            ],
            'App\MessageHandler\SendTeamInvitationEmailHandler' => [
                'public' => true,
            ],
            'App\Query\GetDashboardStats' => [
                'public' => true,
            ],
            'App\Query\GetDomainDetail' => [
                'public' => true,
            ],
            'App\Query\GetDomainVerificationStatus' => [
                'public' => true,
            ],
            'App\Query\GetTopSendersForDomain' => [
                'public' => true,
            ],
            'App\Query\GetDomainPassRateTrend' => [
                'public' => true,
            ],
            'App\Query\GetAllReports' => [
                'public' => true,
            ],
            'App\Query\GetReporterOrgs' => [
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
            'App\Services\Ai\StubAiInsightsService' => [
                'public' => true,
            ],
            'App\Services\Ai\PlanGatedAiInsightsService' => [
                'public' => true,
                'arguments' => [
                    '$inner' => '@App\Services\Ai\StubAiInsightsService',
                ],
            ],
            'App\Services\Ai\AiInsightsService' => [
                'alias' => 'App\Services\Ai\PlanGatedAiInsightsService',
                'public' => true,
            ],
            'App\Command\ResetMonthlyUsageCountersCommand' => [
                'public' => true,
            ],
            'App\Command\PurgeOldDmarcReportsCommand' => [
                'public' => true,
            ],
            'App\Services\Stripe\SubscriptionManager' => [
                'public' => true,
                'arguments' => [
                    '$defaultUri' => '%env(DEFAULT_URI)%',
                ],
            ],
            'App\Command\WarnApproachingPlanLimitsCommand' => [
                'public' => true,
            ],
            'App\Services\Github\FakeGithubApiClient' => [
                'public' => true,
            ],
            'App\Services\Github\GithubApiClient' => [
                'alias' => 'App\Services\Github\FakeGithubApiClient',
                'public' => true,
            ],
            'App\Twig\OpenSourceExtension' => [
                'public' => true,
            ],
            'App\Twig\GithubStatsExtension' => [
                'public' => true,
            ],
            'App\Twig\PlaceholdersExtension' => [
                'public' => true,
            ],
            'App\Services\OgImage\OgImageRenderer' => [
                'public' => true,
            ],
            'App\Services\OgImage\HealthOgImageContentResolver' => [
                'public' => true,
            ],
        ],
    ],
]);
