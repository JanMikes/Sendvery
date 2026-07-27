<?php

declare(strict_types=1);

namespace App\Services\Dns;

use App\Value\Dns\DomainRecheckAvailability;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Throttle for the user-triggered DNS re-check (DEC-059 / D14).
 *
 * The re-check runs live SPF/DKIM/DMARC/MX lookups synchronously inside the web
 * request — deliberately, so the user sees a fresh result the moment the
 * redirect lands. That is only defensible with a hard cap: without one, a
 * signed-in user leaning on the button turns Sendvery into a DNS hammer aimed
 * at third-party resolvers.
 *
 * The bucket is keyed on the DOMAIN, never the client IP. The cost being
 * protected is per-domain, so the cap has to hold across every member of a team
 * and across one person's multiple tabs — an IP key would let five teammates
 * (or one user on phone + laptop) multiply the lookup rate by five.
 */
final readonly class DomainRecheckThrottle
{
    public function __construct(
        #[Target('domain_recheck')]
        private RateLimiterFactoryInterface $domainRecheckLimiter,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Current state WITHOUT spending a token, so merely rendering a page that
     * carries the button never eats into the user's own budget. Symfony's
     * limiters skip their storage write when zero tokens are requested, which
     * is what makes consume(0) a true read-only peek.
     */
    public function peek(string $domainId): DomainRecheckAvailability
    {
        $rateLimit = $this->domainRecheckLimiter->create($domainId)->consume(0);

        // A zero-token consume is always "accepted" — nothing was asked for —
        // so the remaining budget, not isAccepted(), is what says whether a
        // real re-check would go through.
        return $rateLimit->getRemainingTokens() >= 1
            ? DomainRecheckAvailability::available()
            : $this->coolingDown($rateLimit);
    }

    /**
     * Spend a token. `isAvailable` on the result means the caller may run the
     * re-check; otherwise it carries the wait to show the user.
     */
    public function consume(string $domainId): DomainRecheckAvailability
    {
        $rateLimit = $this->domainRecheckLimiter->create($domainId)->consume();

        return $rateLimit->isAccepted()
            ? DomainRecheckAvailability::available()
            : $this->coolingDown($rateLimit);
    }

    private function coolingDown(RateLimit $rateLimit): DomainRecheckAvailability
    {
        // getRetryAfter() is an absolute instant derived from the limiter's own
        // wall clock, so the countdown has to be measured against wall clock
        // too — a frozen clock here would render a nonsense wait.
        return DomainRecheckAvailability::coolingDown(
            $rateLimit->getRetryAfter()->getTimestamp() - $this->clock->now()->getTimestamp(),
        );
    }
}
