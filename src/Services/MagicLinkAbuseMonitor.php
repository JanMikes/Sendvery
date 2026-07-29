<?php

declare(strict_types=1);

namespace App\Services;

use App\Repository\MagicLinkTokenRepository;
use Psr\Log\LoggerInterface;

/**
 * Raises an ops alarm when the GLOBAL magic-link volume spikes. The per-email
 * cap in RequestMagicLinkHandler protects one mailbox from being flooded but
 * says nothing about breadth: the July 2026 abuse campaign drip-fed ~1 request
 * per hour per source IP across hundreds of DISTINCT victim addresses, which
 * no per-email or per-IP limit can see. Every request past the hourly
 * threshold is reported — Sentry groups the repeats into one issue.
 */
final readonly class MagicLinkAbuseMonitor
{
    /**
     * A legitimate hour is single-digit sign-ins (every real login is exactly
     * one request). 20/hour sustained means either a launch-day spike worth
     * knowing about or an abuse campaign — both deserve a page.
     */
    public const int HOURLY_ALERT_THRESHOLD = 20;

    public function __construct(
        private MagicLinkTokenRepository $magicLinkTokenRepository,
        private LoggerInterface $logger,
    ) {
    }

    public function recordRequest(string $email, ?string $requestedIp, ?string $requestedUserAgent, \DateTimeImmutable $now): void
    {
        $lastHourCount = $this->magicLinkTokenRepository->countCreatedSince($now->modify('-1 hour'));

        if ($lastHourCount < self::HOURLY_ALERT_THRESHOLD) {
            return;
        }

        $message = sprintf(
            'Magic-link request volume anomaly: %d requests in the last hour (threshold %d). Possible signup-abuse campaign — inspect magic_link_token.requested_ip.',
            $lastHourCount,
            self::HOURLY_ALERT_THRESHOLD,
        );

        // No Monolog→Sentry handler is configured (see config/packages/sentry.php),
        // so the log line alone would never page anyone — capture explicitly.
        $this->logger->error($message, [
            'email' => $email,
            'requested_ip' => $requestedIp,
            'requested_user_agent' => $requestedUserAgent,
        ]);
        \Sentry\captureException(new \RuntimeException($message));
    }
}
