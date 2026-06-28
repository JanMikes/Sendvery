<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Entity\User;
use App\Exceptions\ManagedDmarcNotAvailable;
use App\Message\AdvanceDmarcPolicy;
use App\Services\DashboardContext;
use App\Value\Dns\PolicyChangeSource;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

final class AdvanceDmarcPolicyController extends AbstractController
{
    public function __construct(
        private readonly DashboardContext $dashboardContext,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    #[Route('/app/domains/{id}/managed-dmarc/advance', name: 'dashboard_domain_advance_dmarc', methods: ['POST'])]
    public function __invoke(Request $request, string $id): Response
    {
        if (!$this->isCsrfTokenValid('domain_dmarc_advance', $request->request->getString('_csrf_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if (!Uuid::isValid($id)) {
            throw $this->createNotFoundException('Domain not found.');
        }

        $user = $this->getUser();
        assert($user instanceof User);

        try {
            $this->commandBus->dispatch(new AdvanceDmarcPolicy(
                domainId: Uuid::fromString($id),
                teamId: $this->dashboardContext->getTeamId()->toString(),
                actorUserId: $user->id,
                source: PolicyChangeSource::Guided,
            ));
        } catch (HandlerFailedException $e) {
            foreach ($e->getWrappedExceptions() as $wrapped) {
                if ($wrapped instanceof ManagedDmarcNotAvailable) {
                    $this->addFlash('error', 'Managed DMARC is available on paid plans. Upgrade to advance your policy.');

                    return $this->redirectToRoute('dashboard_domain_detail', ['id' => $id]);
                }
                if ($wrapped instanceof \RuntimeException) {
                    throw $this->createNotFoundException('Domain not found.');
                }
            }

            throw $e;
        }

        // The handler re-checks readiness server-side and no-ops if not eligible,
        // so the flash speaks to the attempt rather than asserting it advanced.
        $this->addFlash('success', 'Checked your readiness — if your mail is ready, your policy has advanced.');

        return $this->redirectToRoute('dashboard_domain_detail', ['id' => $id]);
    }
}
