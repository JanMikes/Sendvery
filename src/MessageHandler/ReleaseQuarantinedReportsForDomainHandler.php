<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ProcessDmarcReport;
use App\Message\ReleaseQuarantinedReportsForDomain;
use App\Repository\MonitoredDomainRepository;
use App\Repository\QuarantinedDmarcReportRepository;
use App\Services\IdentityProvider;
use App\Services\Stripe\PlanEnforcement;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class ReleaseQuarantinedReportsForDomainHandler
{
    public function __construct(
        private QuarantinedDmarcReportRepository $quarantineRepository,
        private MonitoredDomainRepository $monitoredDomainRepository,
        private PlanEnforcement $planEnforcement,
        private MessageBusInterface $commandBus,
        private IdentityProvider $identityProvider,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ReleaseQuarantinedReportsForDomain $message): void
    {
        $reports = $this->quarantineRepository->findForDomain($message->domainName);

        if ([] === $reports) {
            return;
        }

        // A release is an ingestion like any other and every released report
        // increments the team's monthly counter, so it has to respect the same
        // cap the inbox does — otherwise verifying a domain is a way to walk
        // straight past the plan limit.
        //
        // THE TRAP: release everything and each over-cap report is parked again
        // on arrival, so every trigger would delete and re-create the same rows
        // (with a fresh TTL) in a loop. Releasing at most `allowance` of them
        // means the ones we hand over cannot bounce back.
        $team = $this->monitoredDomainRepository->get($message->domainId)->team;
        $allowance = $this->planEnforcement->remainingMonthlyReportAllowance(
            $team->id->toString(),
            $team->getSubscriptionPlan(),
        );

        $released = 0;

        foreach ($reports as $quarantined) {
            if ($released >= $allowance) {
                // The domain is sorted out; only the cap is holding this one
                // now. Say so on the row: `plan_overage` is excluded from the
                // TTL purge, so a report withheld for a billing reason can't be
                // deleted for a verification reason it no longer has.
                $quarantined->markBlockedByPlanCap();

                continue;
            }

            $this->commandBus->dispatch(new ProcessDmarcReport(
                reportId: $this->identityProvider->nextIdentity(),
                domainId: $message->domainId,
                xmlContent: $quarantined->decompressedXml(),
                sourceEnvelopeId: $quarantined->receivedEmail->id,
            ));

            // The quarantine row's purpose is fulfilled — drop it. If the
            // downstream ProcessDmarcReport finds the report is a duplicate,
            // it short-circuits, so deleting here is safe.
            $this->entityManager->remove($quarantined);
            ++$released;
        }

        $this->logger->info('Released {released} of {total} quarantined report(s) for newly-verified domain {domain}.', [
            'released' => $released,
            'total' => count($reports),
            'domain' => $message->domainName,
        ]);
    }
}
