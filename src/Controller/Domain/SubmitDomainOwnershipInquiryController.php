<?php

declare(strict_types=1);

namespace App\Controller\Domain;

use App\Entity\User;
use App\Exceptions\DomainNotTaken;
use App\Exceptions\InquiryRateLimited;
use App\Message\CreateDomainOwnershipInquiry;
use App\Services\DashboardContext;
use App\Services\IdentityProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class SubmitDomainOwnershipInquiryController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly IdentityProvider $identityProvider,
        private readonly DashboardContext $dashboardContext,
    ) {
    }

    #[Route('/app/domain-taken/notify-admin', name: 'domain_taken_notify_admin', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function __invoke(Request $request): Response
    {
        $domain = strtolower(trim($request->request->getString('domain')));
        if ('' === $domain) {
            return $this->redirectToRoute('dashboard_overview');
        }

        /** @var User $user */
        $user = $this->getUser();

        try {
            $this->commandBus->dispatch(new CreateDomainOwnershipInquiry(
                inquiryId: $this->identityProvider->nextIdentity(),
                domain: $domain,
                inquiringUserId: $user->id,
                inquiringTeamId: $this->dashboardContext->getTeamId(),
            ));
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();

            if ($previous instanceof InquiryRateLimited || $previous instanceof DomainNotTaken) {
                $this->addFlash('domain_taken_error', $previous->getMessage());
            } else {
                $this->addFlash('domain_taken_error', 'Something went wrong sending your request. Please try again.');
            }

            return $this->redirectToRoute('domain_taken', ['domain' => $domain]);
        }

        $this->addFlash('domain_taken_success', 'Thanks — we\'ll review your request and get back to you shortly.');

        return $this->redirectToRoute('domain_taken', ['domain' => $domain]);
    }
}
