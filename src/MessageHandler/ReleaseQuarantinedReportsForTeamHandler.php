<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ProcessDmarcReport;
use App\Message\ReleaseQuarantinedReportsForTeam;
use App\Query\GetReleasableQuarantinedReports;
use App\Repository\QuarantinedDmarcReportRepository;
use App\Repository\TeamRepository;
use App\Services\IdentityProvider;
use App\Services\Stripe\PlanEnforcement;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Gives back the reports a team's own plan cap withheld.
 *
 * Without this, `plan_overage` quarantine was a dead end: nothing released it
 * on upgrade and nothing released it when the monthly counter rolled, so a
 * customer's reports sat there until the TTL purge deleted them — data loss
 * caused by a billing limit, which `never-delete-user-data` forbids.
 *
 * Mirrors ReleaseQuarantinedReportsForDomainHandler (the unverified-domain
 * case); the difference is only what unblocked the report.
 */
#[AsMessageHandler]
final readonly class ReleaseQuarantinedReportsForTeamHandler
{
    public function __construct(
        private TeamRepository $teamRepository,
        private GetReleasableQuarantinedReports $releasableReports,
        private QuarantinedDmarcReportRepository $quarantineRepository,
        private PlanEnforcement $planEnforcement,
        private MessageBusInterface $commandBus,
        private IdentityProvider $identityProvider,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ReleaseQuarantinedReportsForTeam $message): void
    {
        $team = $this->teamRepository->get($message->teamId);
        $teamId = $team->id->toString();

        // Release AT MOST the remaining allowance. Releasing everything would
        // put each report straight back over the cap, and the ingestion gate
        // would park it again — the same rows deleted and re-created on every
        // trigger, with a fresh TTL each time. Anything that doesn't fit stays
        // quarantined and waits for the next period.
        $allowance = $this->planEnforcement->remainingMonthlyReportAllowance($teamId, $team->getSubscriptionPlan());

        $releasable = $this->releasableReports->overCapForTeam($teamId, $allowance);
        if ([] === $releasable) {
            return;
        }

        $this->logger->info('Releasing {count} plan-overage report(s) for team {teamId} — headroom is back.', [
            'count' => count($releasable),
            'teamId' => $teamId,
        ]);

        foreach ($releasable as $row) {
            $quarantined = $this->quarantineRepository->find(Uuid::fromString($row->quarantineId));
            assert(null !== $quarantined);

            $this->commandBus->dispatch(new ProcessDmarcReport(
                reportId: $this->identityProvider->nextIdentity(),
                domainId: Uuid::fromString($row->domainId),
                xmlContent: $quarantined->decompressedXml(),
                sourceEnvelopeId: $quarantined->receivedEmail->id,
            ));

            // Purpose fulfilled — drop the holding row. A duplicate report
            // short-circuits in ProcessDmarcReportHandler, so this is safe even
            // if the same report also arrives from the inbox in the meantime.
            $this->entityManager->remove($quarantined);
        }
    }
}
