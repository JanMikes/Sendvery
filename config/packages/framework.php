<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\App;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler;

return App::config([
    'framework' => [
        'secret' => '%env(APP_SECRET)%',
        'http_method_override' => false,
        'handle_all_throwables' => true,
        // The app only ever receives traffic from a reverse proxy on a
        // private network (Traefik in prod, Docker's bridge in dev, the PHP
        // built-in server in CI), so X-Forwarded-For from private peers is
        // trustworthy — Traefik strips forwarded headers arriving from
        // untrusted clients before adding its own. Without this every
        // getClientIp() returns the proxy's address, which would make the
        // login rate-limiter throttle all humans as one shared "client".
        'trusted_proxies' => 'private_ranges',
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
        // DEC-059 / D14: the "Re-check DNS" button runs live SPF/DKIM/DMARC/MX
        // lookups synchronously inside the web request (deliberately — the user
        // must see a fresh result on redirect), so without a cap any signed-in
        // user can make Sendvery hammer third-party resolvers. Keyed on the
        // DOMAIN id, not the client IP: the cost is per-domain, so the limit has
        // to hold across a team's members and across one person's tabs.
        // fixed_window rather than token_bucket because its retryAfter counts
        // down the real remaining wait, which is what the disabled button shows
        // ("Re-check available in 2m"); token_bucket always reports a whole
        // interval no matter how much of it has already elapsed.
        // Autowires as RateLimiterFactory $domainRecheckLimiter.
        // Per-IP cap for POST /login, the endpoint the July 2026 signup-abuse
        // campaign fed with victim emails. 10/hour is roomy for humans — a
        // whole office behind one NAT signing in the same hour fits — while
        // an address-list walker burns through it in minutes. Keyed on client
        // IP (hence trusted_proxies above). The per-EMAIL cap of 5/hour lives
        // in RequestMagicLinkHandler and protects the opposite axis: one
        // victim mailbox targeted from many IPs.
        // Autowires as RateLimiterFactory $loginFormLimiter.
        'rate_limiter' => [
            'login_form' => [
                'policy' => 'token_bucket',
                'limit' => 10,
                'rate' => ['interval' => '1 hour', 'amount' => 10],
            ],
            'contact_form' => [
                'policy' => 'token_bucket',
                'limit' => 5,
                'rate' => ['interval' => '1 hour', 'amount' => 5],
            ],
            'domain_recheck' => [
                'policy' => 'fixed_window',
                'limit' => 1,
                'interval' => '3 minutes',
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
            // TASK-159: rate-limiter state needs to survive
            // services_resetter->reset() so a single test method can verify
            // that 5 submissions exhaust the bucket and the 6th is blocked
            // (and, for DEC-059, that a second DNS re-check inside 3 minutes
            // is throttled instead of running live lookups again).
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
