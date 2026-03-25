<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Entity\User;
use App\Message\SubmitFeedback;
use App\Services\DashboardContext;
use App\Services\IdentityProvider;
use App\Value\FeedbackType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

final class SubmitFeedbackController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly IdentityProvider $identityProvider,
        private readonly DashboardContext $dashboardContext,
    ) {
    }

    #[Route('/app/feedback', name: 'dashboard_submit_feedback', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $user = $this->getUser();
        assert($user instanceof User);

        $typeValue = $request->request->getString('type');
        $type = FeedbackType::tryFrom($typeValue) ?? FeedbackType::General;
        $message = trim($request->request->getString('message'));
        $page = $request->request->getString('page', $request->headers->get('referer', '/app'));

        if ('' === $message) {
            if ($request->headers->has('Turbo-Frame')) {
                return $this->render('dashboard/_feedback_response.html.twig', [
                    'error' => 'Please enter a message.',
                ]);
            }

            return $this->redirectToRoute('dashboard_overview');
        }

        $this->messageBus->dispatch(new SubmitFeedback(
            feedbackId: $this->identityProvider->nextIdentity(),
            userId: $user->id,
            teamId: $this->dashboardContext->getTeamId(),
            type: $type,
            message: $message,
            page: $page,
        ));

        if ($request->headers->has('Turbo-Frame')) {
            return $this->render('dashboard/_feedback_response.html.twig', [
                'success' => true,
            ]);
        }

        return $this->redirectToRoute('dashboard_overview');
    }
}
