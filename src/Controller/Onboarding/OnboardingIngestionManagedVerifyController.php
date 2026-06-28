<?php

declare(strict_types=1);

namespace App\Controller\Onboarding;

use App\Entity\User;
use App\Repository\MonitoredDomainRepository;
use App\Services\Dns\ManagedDmarcCnameChecker;
use App\Services\TeamProvisioner;
use App\Value\Dns\CnameVerificationOutcome;
use App\Value\Dns\DmarcSetupMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The managed-CNAME counterpart of OnboardingIngestionVerifyController. Runs a
 * LIVE CNAME check (and a live coexisting-TXT check) and renders the three-state
 * managed verify panel. On a verified CNAME it marks the domain verified through
 * the same entity path as the daily sweep, so step 4 and the dashboard agree.
 */
final class OnboardingIngestionManagedVerifyController extends AbstractController
{
    public function __construct(
        private readonly TeamProvisioner $teamProvisioner,
        private readonly MonitoredDomainRepository $monitoredDomainRepository,
        private readonly ManagedDmarcCnameChecker $cnameChecker,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/app/onboarding/ingestion/managed-verify', name: 'onboarding_ingestion_managed_verify', methods: ['GET'])]
    public function __invoke(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if (null !== $user->onboardingCompletedAt) {
            return $this->redirectToRoute('dashboard_overview');
        }

        $teamId = $this->teamProvisioner->provisionForUser($user)->id;
        $domain = $this->monitoredDomainRepository->findLatestForTeam($teamId);

        if (null === $domain) {
            return $this->redirectToRoute('onboarding_domain');
        }

        // A live coexisting-TXT blocks setup before the CNAME can take over.
        $hasConflictingTxt = $this->cnameChecker->hasConflictingDmarcTxt($domain->domain);
        $outcome = $this->cnameChecker->verify($domain->domain);

        if (DmarcSetupMode::ManagedCname === $domain->dmarcSetupMode) {
            $domain->markCnameVerified($outcome, $this->clock->now());
            $this->entityManager->flush();
        }

        return $this->render('onboarding/_managed_verify_panel.html.twig', [
            'domainName' => $domain->domain,
            'cnameTarget' => $this->cnameChecker->expectedTarget($domain->domain) ?? '',
            'outcome' => $outcome->value,
            'verified' => CnameVerificationOutcome::Verified === $outcome,
            'pointsElsewhere' => CnameVerificationOutcome::PointsElsewhere === $outcome,
            'hasConflictingTxt' => $hasConflictingTxt,
        ]);
    }
}
