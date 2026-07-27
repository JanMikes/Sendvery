<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\CheckBlacklist;
use App\Query\GetBlacklistCheckCandidates;
use App\Services\Stripe\PlanLimits;
use App\Value\SubscriptionPlan;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The missing link that made blacklist monitoring real.
 *
 * Every other piece already existed — `CheckBlacklist`, its handler, the DNSBL
 * client, `blacklist_check_result`, `AlertOnBlacklisting`, the dashboard tab,
 * the plan gate. Nothing dispatched the message, so the table was permanently
 * empty while the feature was listed in the Personal plan, described in the
 * pricing FAQ as continuously checking and raising alerts, and marked done on
 * the roadmap. Meanwhile `DomainHealthScorer` filled the gap with a hardcoded
 * 100 that carried a fifth of every domain's grade.
 *
 * RATE LIMITS ARE THE DESIGN CONSTRAINT, not an afterthought. Public DNSBLs are
 * a shared resource Sendvery does not own, and Spamhaus and Barracuda will
 * null-route a resolver that gets noisy — which would break the feature for
 * every customer simultaneously and is not something a retry fixes. The bounds
 * live in {@see GetBlacklistCheckCandidates} (paid teams only, global per-IP
 * freshness, per-domain cap) and are reinforced here by a whole-sweep ceiling,
 * so even a pathological data shape cannot produce an unbounded burst.
 *
 * Dispatches rather than checks inline: each lookup is up to 16 blocking DNS
 * queries, and the worker is where blocking work belongs.
 */
#[AsCommand(
    name: 'sendvery:blacklist:check-all',
    description: 'Queue DNSBL checks for the sending IPs of every paid-plan domain that is due one.',
)]
final class CheckAllBlacklistsCommand extends Command
{
    /**
     * A listing that appeared this morning matters; one re-confirmed six times
     * a day does not. Long enough to keep query volume sane, short enough that
     * a verdict on the dashboard is never more than a day stale.
     */
    public const int CACHE_HOURS = 24;

    /**
     * Most IPs to check for any single domain per sweep, newest senders first.
     */
    public const int PER_DOMAIN_CAP = 10;

    /**
     * Whole-sweep ceiling. The per-domain cap bounds one customer; this bounds
     * all of them together, so growth in the customer base cannot silently turn
     * into a DNSBL ban.
     */
    public const int SWEEP_CAP = 500;

    public function __construct(
        private readonly GetBlacklistCheckCandidates $candidates,
        private readonly MessageBusInterface $commandBus,
        private readonly PlanLimits $planLimits,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'limit',
            null,
            InputOption::VALUE_REQUIRED,
            'Cap the number of checks queued in this sweep.',
            (string) self::SWEEP_CAP,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $limit = max(0, (int) $input->getOption('limit'));

        $due = $this->candidates->due(
            paidPlans: $this->paidPlans(),
            cacheHours: self::CACHE_HOURS,
            perDomainCap: self::PER_DOMAIN_CAP,
        );

        $queued = 0;
        foreach ($due as $candidate) {
            if ($queued >= $limit) {
                break;
            }

            $this->commandBus->dispatch(new CheckBlacklist(
                domainId: $candidate->domainId,
                ipAddress: $candidate->ipAddress,
            ));

            ++$queued;
        }

        // Silent truncation reads as "we covered everything". Say what was left.
        $skipped = count($due) - $queued;
        if ($skipped > 0) {
            $io->warning(sprintf(
                '%d address(es) were due but not queued because the sweep cap of %d was reached. They are picked up on the next run.',
                $skipped,
                $limit,
            ));
        }

        $io->success(sprintf('Queued %d blacklist check(s).', $queued));

        return Command::SUCCESS;
    }

    /**
     * Derived from PlanLimits rather than hardcoded, so a plan gaining or
     * losing the feature cannot leave this sweep disagreeing with the paywall
     * the customer actually sees.
     *
     * @return list<string>
     */
    private function paidPlans(): array
    {
        $plans = [];

        foreach (SubscriptionPlan::cases() as $plan) {
            if ($this->planLimits->hasFeature($plan, 'blacklist_monitoring')) {
                $plans[] = $plan->value;
            }
        }

        return $plans;
    }
}
