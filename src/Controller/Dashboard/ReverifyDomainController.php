<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Message\CheckDomainDns;
use App\Message\SnapshotDomainHealth;
use App\MessageHandler\CheckDomainDnsHandler;
use App\Repository\MonitoredDomainRepository;
use App\Services\DashboardContext;
use App\Services\Dns\DomainRecheckThrottle;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Manual "check my DNS now" escape hatch from the 03:00 cron.
 *
 * Every control that posts here is rendered by the shared
 * `components/RecheckDnsButton.html.twig` component — the dashboard overview
 * banner, the domain detail / health / DNS-history pages, the setup-status and
 * pending banners, and the managed-DMARC card. Adding a new surface means
 * rendering that component, never hand-rolling another form: this file's
 * previous comment listed the surfaces by hand and was wrong (the health page
 * never had a button at all).
 */
final class ReverifyDomainController extends AbstractController
{
    public function __construct(
        private readonly DashboardContext $dashboardContext,
        private readonly MonitoredDomainRepository $monitoredDomainRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly CheckDomainDnsHandler $checkDomainDnsHandler,
        private readonly DomainRecheckThrottle $domainRecheckThrottle,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    #[Route('/app/domains/{id}/reverify', name: 'dashboard_domain_reverify', methods: ['POST'])]
    public function __invoke(string $id, Request $request): Response
    {
        $domain = $this->monitoredDomainRepository->findForTeams(
            Uuid::fromString($id),
            $this->dashboardContext->getTeamIds(),
        );

        if (null === $domain) {
            throw $this->createNotFoundException('Domain not found.');
        }

        // The check below performs live SPF/DKIM/DMARC/MX lookups inside this
        // request, so it is capped at one run per domain per 3 minutes.
        $availability = $this->domainRecheckThrottle->consume($domain->id->toString());

        if (!$availability->isAvailable) {
            // An exhausted budget is normal impatience (or a second tab whose
            // button was still enabled) — neutral copy, no exception, and the
            // user still lands back where they clicked from.
            $this->addFlash('domain_recheck', sprintf(
                'We checked %s moments ago — you can run another check in %s. Limiting this to one check every few minutes keeps us from hammering your DNS provider.',
                $domain->domain,
                $availability->cooldownLabel(),
            ));

            return $this->backToClickedPage($request, $domain->id);
        }

        // Run the same handler the daily cron uses so the dns_check_result row
        // is written and the verification status query reflects today's state.
        ($this->checkDomainDnsHandler)(new CheckDomainDns(domainId: $domain->id));
        $this->entityManager->flush();

        // Snapshot AFTER the flush, mirroring the nightly sweep: the handler
        // reads the check rows this request just wrote, so it has to see them
        // committed. Without this, a user who publishes a record and clicks
        // "Re-check DNS" gets a fixed checklist but a stale grade/score until
        // 03:00 — two surfaces on the same page disagreeing about the same
        // domain.
        $this->commandBus->dispatch(new SnapshotDomainHealth(domainId: $domain->id));

        return $this->backToClickedPage($request, $domain->id);
    }

    /**
     * Send the user back to the page they clicked from so they immediately see
     * the fresh result. The same-host check keeps this from becoming an open
     * redirect; the domain detail page is the fallback.
     */
    private function backToClickedPage(Request $request, UuidInterface $domainId): Response
    {
        $referer = $request->headers->get('referer');
        if (null !== $referer && parse_url($referer, \PHP_URL_HOST) === $request->getHost()) {
            return $this->redirect($referer);
        }

        return $this->redirectToRoute('dashboard_domain_detail', ['id' => $domainId->toString()]);
    }
}
