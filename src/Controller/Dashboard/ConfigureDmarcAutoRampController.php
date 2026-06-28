<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Exceptions\ManagedDmarcNotAvailable;
use App\Message\ConfigureDmarcAutoRamp;
use App\Services\DashboardContext;
use App\Value\Dns\AutoRampAction;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

final class ConfigureDmarcAutoRampController extends AbstractController
{
    public function __construct(
        private readonly DashboardContext $dashboardContext,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    #[Route('/app/domains/{id}/managed-dmarc/auto-ramp', name: 'dashboard_domain_set_auto_ramp', methods: ['POST'])]
    public function __invoke(Request $request, string $id): Response
    {
        if (!$this->isCsrfTokenValid('domain_dmarc_autoramp', $request->request->getString('_csrf_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if (!Uuid::isValid($id)) {
            throw $this->createNotFoundException('Domain not found.');
        }

        $action = AutoRampAction::tryFrom($request->request->getString('action'));
        if (null === $action) {
            throw $this->createNotFoundException('Unknown auto-ramp action.');
        }

        try {
            $this->commandBus->dispatch(new ConfigureDmarcAutoRamp(
                domainId: Uuid::fromString($id),
                teamId: $this->dashboardContext->getTeamId()->toString(),
                action: $action,
            ));
        } catch (HandlerFailedException $e) {
            foreach ($e->getWrappedExceptions() as $wrapped) {
                if ($wrapped instanceof ManagedDmarcNotAvailable) {
                    $this->addFlash('error', 'Auto-drive is available on paid plans. Upgrade to let Sendvery drive you to full enforcement automatically.');

                    return $this->redirectToRoute('dashboard_domain_detail', ['id' => $id]);
                }
                if ($wrapped instanceof \RuntimeException) {
                    throw $this->createNotFoundException('Domain not found.');
                }
            }

            throw $e;
        }

        $this->addFlash('success', match ($action) {
            AutoRampAction::Enable => 'Auto-drive is on. We’ll advance you toward reject, one safe tier at a time.',
            AutoRampAction::Disable => 'Auto-drive turned off. Your current policy stays live.',
            AutoRampAction::Pause => 'Auto-drive paused. We won’t tighten until you resume.',
            AutoRampAction::Resume => 'Auto-drive resumed.',
        });

        return $this->redirectToRoute('dashboard_domain_detail', ['id' => $id]);
    }
}
