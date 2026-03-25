<?php

declare(strict_types=1);

namespace App\Controller;

use App\Services\Dns\DomainHealthScorer;
use App\Services\Dns\EmailAuthChecker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeCheckerController extends AbstractController
{
    public function __construct(
        private readonly EmailAuthChecker $emailAuthChecker,
        private readonly DomainHealthScorer $healthScorer,
    ) {
    }

    #[Route('/api/check-domain', name: 'api_check_domain', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $domain = $request->request->getString('domain');

        if ($domain === '') {
            return new Response('Domain is required.', Response::HTTP_BAD_REQUEST);
        }

        $result = $this->emailAuthChecker->check($domain);
        $healthScore = $this->healthScorer->score($result);

        return $this->render('tools/_results/home-checker-results.html.twig', [
            'result' => $result,
            'healthScore' => $healthScore,
            'domain' => $domain,
        ]);
    }
}
