<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\App;

return App::config([
    'framework' => [
        'messenger' => [
            'buses' => [
                'command_bus' => [
                    'middleware' => [
                        'doctrine_transaction',
                    ],
                ],
                'event_bus' => [
                    'default_middleware' => [
                        'allow_no_handlers' => true,
                    ],
                    'middleware' => [
                        'doctrine_transaction',
                    ],
                ],
            ],
            'default_bus' => 'command_bus',
            'failure_transport' => 'failed',
            'transports' => [
                'sync' => ['dsn' => 'sync://'],
                'failed' => ['dsn' => 'doctrine://default?queue_name=failed'],
                'async' => [
                    'dsn' => '%env(MESSENGER_TRANSPORT_DSN)%',
                    // Transient upstream failures (Seznam SMTP 421 throttling,
                    // IMAP hiccups, Anthropic timeouts) need breathing room,
                    // not the default three 1s/2s/4s rapid-fire retries:
                    // back off 5s → 30s → 3m → 15m (18m capped), then land
                    // in `failed` for manual messenger:failed:retry.
                    'retry_strategy' => [
                        'max_retries' => 4,
                        'delay' => 5000,
                        'multiplier' => 6,
                        'max_delay' => 900000,
                    ],
                ],
            ],
            'routing' => [
                // Decouple the Anthropic call for anomaly insights from report
                // ingestion. The prod `worker` container already consumes `async`
                // (Doctrine transport); a slow/failing API call can't roll back
                // the parse, and exhausted retries land in `failed`.
                \App\Message\GenerateAnomalyInsight::class => 'async',
                \App\Message\GenerateRemediationInsight::class => 'async',
                // A DNS check can take a while (DKIM selector brute-force,
                // slow nameservers) — never run it inside a web request. The
                // sync call sites (re-verify button, onboarding verify, the
                // nightly sweep) invoke CheckDomainDnsHandler directly and are
                // unaffected by this routing.
                \App\Message\CheckDomainDns::class => 'async',
                // The central-inbox pipeline belongs in the worker, one
                // envelope per message: a transient failure (SMTP 421 while
                // emailing an alert, an IMAP hiccup) retries only that one
                // envelope with backoff instead of collapsing the whole poll
                // batch synchronously inside the cron container.
                \App\Message\PollReportsInbox::class => 'async',
                \App\Message\ProcessReceivedReportEmail::class => 'async',
                // Handing back a plan-overage backlog can mean thousands of
                // reports, and the trigger is either a Stripe webhook (which
                // must answer immediately) or the midnight usage-reset cron
                // fanning out over every affected team.
                \App\Message\ReleaseQuarantinedReportsForTeam::class => 'async',
                // One blacklist check is up to 16 blocking DNS queries (8 DNSBLs,
                // plus a TXT lookup for each hit), and the nightly sweep fans out
                // over every paid domain's sending IPs. Running that inline would
                // block the cron container for the length of the slowest resolver
                // and give a single unresponsive DNSBL the power to stall the whole
                // sweep; per-message retry with backoff isolates that to one IP.
                \App\Message\CheckBlacklist::class => 'async',
            ],
        ],
    ],
]);
