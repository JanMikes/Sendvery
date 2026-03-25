<?php

declare(strict_types=1);

namespace App\Controller;

use App\Query\GetDomainHealthHistory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PublicDomainHealthController extends AbstractController
{
    public function __construct(
        private readonly GetDomainHealthHistory $getDomainHealthHistory,
    ) {
    }

    #[Route('/health/{hash}', name: 'public_domain_health')]
    public function __invoke(string $hash): Response
    {
        $snapshot = $this->getDomainHealthHistory->findByShareHash($hash);

        if (null === $snapshot) {
            throw $this->createNotFoundException('Health report not found.');
        }

        $domainInfo = $this->getDomainHealthHistory->getDomainNameByShareHash($hash);

        return $this->render('public/domain_health.html.twig', [
            'snapshot' => $snapshot,
            'domainName' => $domainInfo['domain_name'] ?? 'Unknown',
        ]);
    }
}
