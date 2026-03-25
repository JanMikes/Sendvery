<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Query\GetBlacklistStatus;
use App\Query\GetDomainDetail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class BlacklistStatusController extends AbstractController
{
    public function __construct(
        private readonly GetDomainDetail $getDomainDetail,
        private readonly GetBlacklistStatus $getBlacklistStatus,
    ) {
    }

    #[Route('/app/domains/{id}/blacklist', name: 'dashboard_blacklist_status')]
    public function __invoke(string $id): Response
    {
        $domain = $this->getDomainDetail->forDomain($id);

        if (null === $domain) {
            throw $this->createNotFoundException('Domain not found.');
        }

        $statusResults = $this->getBlacklistStatus->forDomain($id);

        return $this->render('dashboard/blacklist_status.html.twig', [
            'domain' => $domain,
            'statusResults' => $statusResults,
        ]);
    }
}
