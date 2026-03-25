<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Message\MarkAlertAsRead;
use App\Query\GetAlertDetail;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

final class ShowAlertDetailController extends AbstractController
{
    public function __construct(
        private readonly GetAlertDetail $getAlertDetail,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    #[Route('/app/alerts/{id}', name: 'dashboard_alert_detail')]
    public function __invoke(string $id): Response
    {
        $alert = $this->getAlertDetail->forAlert($id);

        if (null === $alert) {
            throw $this->createNotFoundException('Alert not found.');
        }

        if (!$alert->isRead) {
            $this->commandBus->dispatch(new MarkAlertAsRead(
                alertId: Uuid::fromString($id),
            ));
        }

        return $this->render('dashboard/alert_detail.html.twig', [
            'alert' => $alert,
        ]);
    }
}
