<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Entity\User;
use App\Exceptions\ManagedDmarcNotAvailable;
use App\Message\EnableManagedDmarc;
use App\Services\DashboardContext;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

final class EnableManagedDmarcController extends AbstractController
{
    public function __construct(
        private readonly DashboardContext $dashboardContext,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    #[Route('/app/domains/{id}/managed-dmarc/enable', name: 'dashboard_domain_enable_managed_dmarc', methods: ['POST'])]
    public function __invoke(Request $request, string $id): Response
    {
        if (!$this->isCsrfTokenValid('domain_managed_enable', $request->request->getString('_csrf_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if (!Uuid::isValid($id)) {
            throw $this->createNotFoundException('Domain not found.');
        }

        $user = $this->getUser();
        assert($user instanceof User);

        try {
            $this->commandBus->dispatch(new EnableManagedDmarc(
                domainId: Uuid::fromString($id),
                teamId: $this->dashboardContext->getTeamId()->toString(),
                actorUserId: $user->id,
            ));
        } catch (HandlerFailedException $e) {
            foreach ($e->getWrappedExceptions() as $wrapped) {
                if ($wrapped instanceof ManagedDmarcNotAvailable) {
                    $this->addFlash('error', 'Managed DMARC is available on paid plans. Upgrade to enable it.');

                    return $this->redirectToRoute('dashboard_domain_detail', ['id' => $id]);
                }
                if ($wrapped instanceof \RuntimeException) {
                    throw $this->createNotFoundException('Domain not found.');
                }
            }

            throw $e;
        }

        $this->addFlash('success', 'Managed DMARC enabled. Add the CNAME below and we’ll start hosting your DMARC policy.');

        return $this->redirectToRoute('dashboard_domain_detail', ['id' => $id]);
    }
}
