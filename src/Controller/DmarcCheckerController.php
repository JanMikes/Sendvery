<?php

declare(strict_types=1);

namespace App\Controller;

use App\Services\Dns\DmarcChecker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DmarcCheckerController extends AbstractController
{
    public function __construct(
        private readonly DmarcChecker $dmarcChecker,
    ) {
    }

    #[Route('/tools/dmarc-checker', name: 'tools_dmarc_checker', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $result = null;
        $domain = $request->request->getString('domain');

        if ($request->isMethod('POST') && '' !== $domain) {
            $result = $this->dmarcChecker->check($domain);

            if ($request->isXmlHttpRequest()) {
                return $this->render('tools/_results/dmarc-results.html.twig', [
                    'result' => $result,
                    'domain' => $domain,
                ]);
            }
        }

        return $this->render('tools/dmarc-checker.html.twig', [
            'result' => $result,
            'domain' => $domain,
        ]);
    }
}
