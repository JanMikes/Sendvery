<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Exceptions\ManagedDmarcNotAvailable;
use App\Message\EnableManagedDmarc;
use App\Query\GetTeamPlan;
use App\Repository\MonitoredDomainRepository;
use App\Services\Dns\DmarcChecker;
use App\Services\Dns\ManagedDmarcCnameChecker;
use App\Services\Stripe\PlanEnforcement;
use App\Value\DmarcPolicy;
use App\Value\Dns\CnameVerificationOutcome;
use App\Value\Dns\ManagedDmarcPolicy;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class EnableManagedDmarcHandler
{
    public function __construct(
        private MonitoredDomainRepository $monitoredDomainRepository,
        private GetTeamPlan $getTeamPlan,
        private PlanEnforcement $planEnforcement,
        private DmarcChecker $dmarcChecker,
        private ManagedDmarcCnameChecker $cnameChecker,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(EnableManagedDmarc $message): void
    {
        $domain = $this->monitoredDomainRepository->findForTeams(
            $message->domainId,
            [Uuid::fromString($message->teamId)],
        );

        if (null === $domain) {
            throw new \RuntimeException('Domain not found or not owned by team.');
        }

        $plan = $this->getTeamPlan->forTeam($message->teamId);
        if (!$this->planEnforcement->canUseManagedDmarc($plan)) {
            throw new ManagedDmarcNotAvailable($plan);
        }

        $now = $this->clock->now();

        // Enforcement-preserving: seed from the customer's CURRENT live DMARC
        // record so switching to managed never silently downgrades them.
        $domain->enableManagedDmarc($this->seedFromLiveRecord($domain->domain), $now);

        // If the customer pre-pointed the CNAME at us, mark it verified now.
        if (CnameVerificationOutcome::Verified === $this->cnameChecker->verify($domain->domain)) {
            $domain->markCnameVerified(CnameVerificationOutcome::Verified, $now);
        }
    }

    private function seedFromLiveRecord(string $domainName): ManagedDmarcPolicy
    {
        $check = $this->dmarcChecker->check($domainName);

        $p = null !== $check->policy ? DmarcPolicy::tryFrom($check->policy) : null;
        if (null === $p || DmarcPolicy::None === $p) {
            return ManagedDmarcPolicy::monitoring();
        }

        $sp = null !== $check->subdomainPolicy ? DmarcPolicy::tryFrom($check->subdomainPolicy) : null;

        return new ManagedDmarcPolicy($p, $sp, $check->pct ?? 100);
    }
}
