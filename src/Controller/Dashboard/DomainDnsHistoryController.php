<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Query\GetDomainDetail;
use App\Query\GetDomainDnsHistory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DomainDnsHistoryController extends AbstractController
{
    public function __construct(
        private readonly GetDomainDetail $getDomainDetail,
        private readonly GetDomainDnsHistory $getDomainDnsHistory,
    ) {
    }

    #[Route('/app/domains/{id}/dns-history', name: 'dashboard_domain_dns_history')]
    public function __invoke(string $id): Response
    {
        $domain = $this->getDomainDetail->forDomain($id);

        if (null === $domain) {
            throw $this->createNotFoundException('Domain not found.');
        }

        $history = $this->getDomainDnsHistory->forDomain($id);

        return $this->render('dashboard/domain_dns_history.html.twig', [
            'domain' => $domain,
            'history' => $history,
        ]);
    }
}
