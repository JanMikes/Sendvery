<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Entity\User;
use App\Exceptions\ManagedDmarcNotAvailable;
use App\Message\SetDmarcPolicy;
use App\Services\DashboardContext;
use App\Value\DmarcPolicy;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

final class SetDomainDmarcPolicyController extends AbstractController
{
    public function __construct(
        private readonly DashboardContext $dashboardContext,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    #[Route('/app/domains/{id}/managed-dmarc/policy', name: 'dashboard_domain_set_dmarc_policy', methods: ['POST'])]
    public function __invoke(Request $request, string $id): Response
    {
        if (!$this->isCsrfTokenValid('domain_dmarc_policy', $request->request->getString('_csrf_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if (!Uuid::isValid($id)) {
            throw $this->createNotFoundException('Domain not found.');
        }

        $policy = DmarcPolicy::tryFrom($request->request->getString('policy'));
        if (null === $policy) {
            $this->addFlash('error', 'Pick a valid DMARC policy.');

            return $this->redirectToRoute('dashboard_domain_detail', ['id' => $id]);
        }

        // "same" (or empty) means inherit the top-level policy → no explicit sp.
        $subdomain = $request->request->getString('subdomain_policy');
        $subdomainPolicy = '' === $subdomain || 'same' === $subdomain ? null : DmarcPolicy::tryFrom($subdomain);

        $pct = $request->request->getInt('pct', 100);
        if ($pct < 1 || $pct > 100) {
            $pct = 100;
        }

        $user = $this->getUser();
        assert($user instanceof User);

        try {
            $this->commandBus->dispatch(new SetDmarcPolicy(
                domainId: Uuid::fromString($id),
                teamId: $this->dashboardContext->getTeamId()->toString(),
                actorUserId: $user->id,
                p: $policy,
                sp: $subdomainPolicy,
                pct: $pct,
            ));
        } catch (HandlerFailedException $e) {
            foreach ($e->getWrappedExceptions() as $wrapped) {
                if ($wrapped instanceof ManagedDmarcNotAvailable) {
                    $this->addFlash('error', 'Managed DMARC is available on paid plans. Upgrade to change your policy.');

                    return $this->redirectToRoute('dashboard_domain_detail', ['id' => $id]);
                }
                if ($wrapped instanceof \RuntimeException) {
                    throw $this->createNotFoundException('Domain not found.');
                }
            }

            throw $e;
        }

        $this->addFlash('success', sprintf('DMARC policy published: p=%s.', $policy->value));

        return $this->redirectToRoute('dashboard_domain_detail', ['id' => $id]);
    }
}
