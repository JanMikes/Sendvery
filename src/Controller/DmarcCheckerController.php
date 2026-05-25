<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DmarcCheckerController extends AbstractController
{
    /**
     * Default `rua` mailbox for the public DMARC generator. We pre-fill it
     * with our own reports endpoint so visitors can paste the generated
     * record straight into DNS and have aggregate reports flow into Sendvery
     * the moment they add the domain.
     */
    private const string DEFAULT_RUA = 'reports@sendvery.com';

    #[Route('/tools/dmarc-checker', name: 'tools_dmarc_checker', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        return $this->render('tools/dmarc-checker.html.twig', [
            'initialDomain' => trim($request->query->getString('domain')),
            'dmarcDefaultRua' => self::DEFAULT_RUA,
        ]);
    }
}
