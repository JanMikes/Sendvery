<?php

declare(strict_types=1);

namespace App\Controller;

use App\Services\Dns\MxChecker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class MxCheckerController extends AbstractController
{
    public function __construct(
        private readonly MxChecker $mxChecker,
    ) {
    }

    #[Route('/tools/mx-checker', name: 'tools_mx_checker', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $result = null;
        $domain = $request->request->getString('domain');

        if ($request->isMethod('POST') && '' !== $domain) {
            $result = $this->mxChecker->check($domain);

            if ($request->isXmlHttpRequest()) {
                return $this->render('tools/_results/mx-results.html.twig', [
                    'result' => $result,
                    'domain' => $domain,
                ]);
            }
        }

        return $this->render('tools/mx-checker.html.twig', [
            'result' => $result,
            'domain' => $domain,
        ]);
    }
}
