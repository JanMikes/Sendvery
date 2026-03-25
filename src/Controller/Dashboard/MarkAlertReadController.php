<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Message\MarkAlertAsRead;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

final class MarkAlertReadController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    #[Route('/app/alerts/{id}/read', name: 'dashboard_alert_mark_read', methods: ['POST'])]
    public function __invoke(string $id): Response
    {
        $this->commandBus->dispatch(new MarkAlertAsRead(
            alertId: Uuid::fromString($id),
        ));

        return $this->redirectToRoute('dashboard_alerts');
    }
}
