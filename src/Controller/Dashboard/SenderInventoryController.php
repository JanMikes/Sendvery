<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Query\GetDomainDetail;
use App\Query\GetSenderInventory;
use App\Services\DashboardContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SenderInventoryController extends AbstractController
{
    public function __construct(
        private readonly DashboardContext $dashboardContext,
        private readonly GetDomainDetail $getDomainDetail,
        private readonly GetSenderInventory $getSenderInventory,
    ) {
    }

    #[Route('/app/domains/{domainId}/senders', name: 'dashboard_sender_inventory', methods: ['GET'])]
    public function __invoke(string $domainId, Request $request): Response
    {
        $teamIds = $this->dashboardContext->getTeamIdStrings();
        $domain = $this->getDomainDetail->forDomain($domainId, $teamIds);

        if (null === $domain) {
            throw $this->createNotFoundException('Domain not found.');
        }

        $filterParam = $request->query->getString('filter');
        $authorizedFilter = match ($filterParam) {
            'authorized' => true,
            'unauthorized' => false,
            default => null,
        };

        $senders = $this->getSenderInventory->forDomain($domainId, $teamIds, $authorizedFilter);

        return $this->render('dashboard/sender_inventory.html.twig', [
            'domain' => $domain,
            'senders' => $senders,
            'filter' => $filterParam,
        ]);
    }
}
