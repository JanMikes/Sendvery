<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Entity\User;
use App\Message\DisableManagedDmarc;
use App\Services\DashboardContext;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

final class SwitchManagedDmarcToSelfTxtController extends AbstractController
{
    public function __construct(
        private readonly DashboardContext $dashboardContext,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    #[Route('/app/domains/{id}/managed-dmarc/switch-to-self', name: 'dashboard_domain_switch_to_self_txt', methods: ['POST'])]
    public function __invoke(Request $request, string $id): Response
    {
        if (!$this->isCsrfTokenValid('domain_managed_to_self', $request->request->getString('_csrf_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if (!Uuid::isValid($id)) {
            throw $this->createNotFoundException('Domain not found.');
        }

        $user = $this->getUser();
        assert($user instanceof User);

        // No entitlement gate — taking control back must always work, even when frozen.
        try {
            $this->commandBus->dispatch(new DisableManagedDmarc(
                domainId: Uuid::fromString($id),
                teamId: $this->dashboardContext->getTeamId()->toString(),
                actorUserId: $user->id,
            ));
        } catch (HandlerFailedException $e) {
            foreach ($e->getWrappedExceptions() as $wrapped) {
                if ($wrapped instanceof \RuntimeException) {
                    throw $this->createNotFoundException('Domain not found.');
                }
            }

            throw $e;
        }

        $this->addFlash('success', 'Switched back to a self-managed record. Replace the CNAME with the TXT below, then you own it directly.');

        return $this->redirectToRoute('dashboard_domain_detail', ['id' => $id]);
    }
}
