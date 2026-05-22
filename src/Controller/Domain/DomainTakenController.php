<?php

declare(strict_types=1);

namespace App\Controller\Domain;

use App\Repository\DomainOwnershipInquiryRepository;
use App\Repository\MonitoredDomainRepository;
use App\Services\DashboardContext;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class DomainTakenController extends AbstractController
{
    public function __construct(
        private readonly MonitoredDomainRepository $monitoredDomainRepository,
        private readonly DomainOwnershipInquiryRepository $inquiryRepository,
        private readonly DashboardContext $dashboardContext,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/app/domain-taken', name: 'domain_taken', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function __invoke(Request $request): Response
    {
        $domainInput = strtolower(trim($request->query->getString('domain')));
        if ('' === $domainInput) {
            return $this->redirectToRoute('dashboard_overview');
        }

        $existing = $this->monitoredDomainRepository->findAnyByName($domainInput);
        if (null === $existing) {
            // No conflict — user probably reached this page with a stale URL.
            return $this->redirectToRoute('dashboard_domain_add', ['domain' => $domainInput]);
        }

        $teamId = $this->dashboardContext->getTeamId();
        if ($existing->team->id->toString() === $teamId->toString()) {
            // It's their own team's domain — just send them there.
            return $this->redirectToRoute('dashboard_domain_detail', ['id' => $existing->id]);
        }

        $user = $this->getUser();
        assert(null !== $user);

        $alreadyPinged = $this->inquiryRepository->hasRecentForUserAndDomain(
            $user instanceof \App\Entity\User ? $user->id : $teamId,
            $domainInput,
            $this->clock->now()->modify('-24 hours'),
        );

        return $this->render('domain/taken.html.twig', [
            'domain' => $domainInput,
            'alreadyPinged' => $alreadyPinged,
        ]);
    }
}
