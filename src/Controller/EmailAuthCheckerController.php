<?php

declare(strict_types=1);

namespace App\Controller;

use App\Services\Dns\DomainHealthScorer;
use App\Services\Dns\EmailAuthChecker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class EmailAuthCheckerController extends AbstractController
{
    public function __construct(
        private readonly EmailAuthChecker $emailAuthChecker,
        private readonly DomainHealthScorer $healthScorer,
    ) {
    }

    #[Route('/tools/email-auth-checker', name: 'tools_email_auth_checker', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $result = null;
        $healthScore = null;
        $domain = $request->request->getString('domain');

        if ($request->isMethod('POST') && '' !== $domain) {
            $result = $this->emailAuthChecker->check($domain);
            $healthScore = $this->healthScorer->score($result);

            if ($request->isXmlHttpRequest()) {
                return $this->render('tools/_results/email-auth-results.html.twig', [
                    'result' => $result,
                    'healthScore' => $healthScore,
                    'domain' => $domain,
                ]);
            }
        }

        return $this->render('tools/email-auth-checker.html.twig', [
            'result' => $result,
            'healthScore' => $healthScore,
            'domain' => $domain,
        ]);
    }
}
