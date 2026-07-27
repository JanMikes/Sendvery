<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Services\Dns\GuidedDnsSetupProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Turbo-frame endpoint behind the guided DNS setup surface's live updates.
 *
 * It renders the SAME component the two full pages render, so a polled refresh
 * cannot drift from what a reload would show. It deliberately does NOT run a
 * live DNS check: the first check was queued on the async transport when the
 * domain was added, and re-checking here would both bypass the 3-minute
 * per-domain throttle and turn a background poll into a slow request. This
 * endpoint only re-reads what the worker has written.
 */
final class DomainDnsSetupFrameController extends AbstractController
{
    public function __construct(
        private readonly GuidedDnsSetupProvider $guidedDnsSetupProvider,
    ) {
    }

    #[Route('/app/domains/{id}/dns-setup', name: 'dashboard_domain_dns_setup_frame', methods: ['GET'])]
    public function __invoke(string $id, Request $request): Response
    {
        $view = $this->guidedDnsSetupProvider->forDomainId($id);

        if (null === $view) {
            throw $this->createNotFoundException('Domain not found.');
        }

        // Only the two known render modes are accepted — the parameter reaches
        // this endpoint from a URL the user can edit, and an unrecognised value
        // must not leak into a template branch.
        $mode = 'compact' === $request->query->get('mode') ? 'compact' : 'full';

        return $this->render('components/_guided_dns_setup_frame.html.twig', [
            'setup' => $view->setup,
            'domainId' => $view->domainId,
            'domainName' => $view->domainName,
            'mode' => $mode,
        ]);
    }
}
