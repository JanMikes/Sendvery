<?php

declare(strict_types=1);

namespace App\Controller;

use App\Services\Dns\SpfChecker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SpfCheckerController extends AbstractController
{
    public function __construct(
        private readonly SpfChecker $spfChecker,
    ) {
    }

    #[Route('/tools/spf-checker', name: 'tools_spf_checker', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $result = null;
        $domain = $request->request->getString('domain');

        if ($request->isMethod('POST') && '' !== $domain) {
            $result = $this->spfChecker->check($domain);

            if ($request->isXmlHttpRequest()) {
                return $this->render('tools/_results/spf-results.html.twig', [
                    'result' => $result,
                    'domain' => $domain,
                ]);
            }
        }

        return $this->render('tools/spf-checker.html.twig', [
            'result' => $result,
            'domain' => $domain,
        ]);
    }
}
