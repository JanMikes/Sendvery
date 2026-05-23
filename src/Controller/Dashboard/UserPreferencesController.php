<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Entity\User;
use App\Query\GetMutedAlerts;
use App\Services\DashboardContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class UserPreferencesController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DashboardContext $dashboardContext,
        private readonly GetMutedAlerts $getMutedAlerts,
    ) {
    }

    #[Route('/app/settings/preferences', name: 'dashboard_preferences', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $user = $this->getUser();
        assert($user instanceof User);

        if ($request->isMethod('POST')) {
            $user->emailDigestEnabled = $request->request->getBoolean('email_digest_enabled');
            $user->emailAlertsEnabled = $request->request->getBoolean('email_alerts_enabled');
            $this->entityManager->flush();

            return $this->render('dashboard/preferences.html.twig', [
                'user' => $user,
                'mutedAlerts' => $this->getMutedAlerts->forTeams($this->dashboardContext->getTeamIdStrings()),
                'success' => 'Preferences saved successfully.',
            ]);
        }

        return $this->render('dashboard/preferences.html.twig', [
            'user' => $user,
            'mutedAlerts' => $this->getMutedAlerts->forTeams($this->dashboardContext->getTeamIdStrings()),
        ]);
    }
}
