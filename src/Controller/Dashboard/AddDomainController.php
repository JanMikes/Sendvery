<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\FormData\AddDomainData;
use App\Message\AddDomain;
use App\Query\GetTeamPlan;
use App\Services\DashboardContext;
use App\Services\IdentityProvider;
use App\Services\Stripe\PlanEnforcement;
use App\Services\Stripe\PlanLimits;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class AddDomainController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly IdentityProvider $identityProvider,
        private readonly ValidatorInterface $validator,
        private readonly DashboardContext $dashboardContext,
        private readonly PlanEnforcement $planEnforcement,
        private readonly PlanLimits $planLimits,
        private readonly GetTeamPlan $getTeamPlan,
    ) {
    }

    #[Route('/app/domains/add', name: 'dashboard_domain_add', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $teamId = $this->dashboardContext->getTeamId();
        $plan = $this->getTeamPlan->forTeam($teamId->toString());
        $canAdd = $this->planEnforcement->canAddDomain($teamId->toString(), $plan);
        $data = new AddDomainData();
        $errors = [];

        if ($request->isMethod('POST')) {
            if (!$canAdd) {
                $errors[] = sprintf(
                    'You have reached your domain limit (%d). Upgrade your plan to add more domains.',
                    $this->planLimits->getMaxDomains($plan),
                );
            } else {
                $data->domainName = trim($request->request->getString('domain_name'));

                $violations = $this->validator->validate($data);

                if (count($violations) > 0) {
                    foreach ($violations as $violation) {
                        $errors[] = (string) $violation->getMessage();
                    }
                } else {
                    $domainId = $this->identityProvider->nextIdentity();

                    $this->commandBus->dispatch(new AddDomain(
                        domainId: $domainId,
                        teamId: $teamId,
                        domainName: $data->domainName,
                    ));

                    return $this->redirectToRoute('dashboard_domain_detail', ['id' => $domainId]);
                }
            }
        }

        return $this->render('dashboard/domain_add.html.twig', [
            'data' => $data,
            'errors' => $errors,
            'canAddDomain' => $canAdd,
            'currentPlan' => $plan,
            'maxDomains' => $this->planLimits->getMaxDomains($plan),
            'domainCount' => $this->planEnforcement->getDomainCount($teamId->toString()),
        ]);
    }
}
