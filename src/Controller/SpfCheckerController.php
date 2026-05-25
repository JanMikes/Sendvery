<?php

declare(strict_types=1);

namespace App\Controller;

use App\Services\Dns\SpfProviderRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SpfCheckerController extends AbstractController
{
    public function __construct(
        private readonly SpfProviderRegistry $spfProviderRegistry,
    ) {
    }

    #[Route('/tools/spf-checker', name: 'tools_spf_checker', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        return $this->render('tools/spf-checker.html.twig', [
            'initialDomain' => trim($request->query->getString('domain')),
            'spfProviders' => $this->spfProviderRegistry->all(),
            'spfProvidersJson' => $this->spfProviderRegistry->allAsJson(),
        ]);
    }
}
