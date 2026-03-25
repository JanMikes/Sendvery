<?php

declare(strict_types=1);

namespace App\Controller;

use App\Services\Dns\DkimChecker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DkimCheckerController extends AbstractController
{
    public function __construct(
        private readonly DkimChecker $dkimChecker,
    ) {
    }

    #[Route('/tools/dkim-checker', name: 'tools_dkim_checker', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $result = null;
        $domain = $request->request->getString('domain');
        $selector = $request->request->getString('selector');

        if ($request->isMethod('POST') && $domain !== '') {
            $result = $this->dkimChecker->check($domain, $selector !== '' ? $selector : null);

            if ($request->isXmlHttpRequest()) {
                return $this->render('tools/_results/dkim-results.html.twig', [
                    'result' => $result,
                    'domain' => $domain,
                ]);
            }
        }

        return $this->render('tools/dkim-checker.html.twig', [
            'result' => $result,
            'domain' => $domain,
            'selector' => $selector,
        ]);
    }
}
