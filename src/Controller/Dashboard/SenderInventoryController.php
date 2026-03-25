<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Message\MarkSenderAuthorized;
use App\Query\GetDomainDetail;
use App\Query\GetSenderInventory;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

final class SenderInventoryController extends AbstractController
{
    public function __construct(
        private readonly GetDomainDetail $getDomainDetail,
        private readonly GetSenderInventory $getSenderInventory,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    #[Route('/app/domains/{id}/senders', name: 'dashboard_sender_inventory')]
    public function __invoke(string $id, Request $request): Response
    {
        $domain = $this->getDomainDetail->forDomain($id);

        if (null === $domain) {
            throw $this->createNotFoundException('Domain not found.');
        }

        if ($request->isMethod('POST') && $request->request->has('sender_id')) {
            $senderId = Uuid::fromString($request->request->getString('sender_id'));
            $isAuthorized = $request->request->getBoolean('is_authorized');

            $this->commandBus->dispatch(new MarkSenderAuthorized(
                senderId: $senderId,
                isAuthorized: $isAuthorized,
            ));

            return $this->redirectToRoute('dashboard_sender_inventory', ['id' => $id]);
        }

        $filterParam = $request->query->getString('filter');
        $authorizedFilter = match ($filterParam) {
            'authorized' => true,
            'unauthorized' => false,
            default => null,
        };

        $senders = $this->getSenderInventory->forDomain($id, $authorizedFilter);

        return $this->render('dashboard/sender_inventory.html.twig', [
            'domain' => $domain,
            'senders' => $senders,
            'filter' => $filterParam,
        ]);
    }
}
