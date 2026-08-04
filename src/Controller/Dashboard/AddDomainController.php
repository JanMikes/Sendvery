<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\FormData\AddDomainData;
use App\Message\AddDomain;
use App\Query\GetTeamPlan;
use App\Repository\MonitoredDomainRepository;
use App\Repository\TeamRepository;
use App\Services\DashboardContext;
use App\Services\IdentityProvider;
use App\Services\Stripe\PlanEnforcement;
use App\Services\Stripe\PlanLimits;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
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
        private readonly MonitoredDomainRepository $monitoredDomainRepository,
        private readonly TeamRepository $teamRepository,
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
        $alreadyConnected = null;

        if ($request->isMethod('GET')) {
            $data->domainName = trim($request->query->getString('domain'));
        }

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
                    $conflict = $this->monitoredDomainRepository->findAnyByName($data->domainName);

                    if (null !== $conflict) {
                        // Hard-block when another team has already claimed this name.
                        if ($conflict->team->id->toString() !== $teamId->toString()) {
                            return $this->redirectToRoute('domain_taken', ['domain' => $data->domainName]);
                        }

                        // Ours already. Persisting a second row would trip the
                        // system-wide unique index on lower(domain) and answer a
                        // duplicate submit with a 500 — and there is nothing to
                        // fix anyway: the domain IS monitored, which is what the
                        // user asked for. Show them where it lives, in the
                        // neutral tone a satisfied intent deserves.
                        $alreadyConnected = $conflict;
                    } else {
                        $domainId = $this->identityProvider->nextIdentity();

                        try {
                            $this->commandBus->dispatch(new AddDomain(
                                domainId: $domainId,
                                teamId: $teamId,
                                domainName: $data->domainName,
                            ));
                        } catch (HandlerFailedException $e) {
                            if (!$e->getPrevious() instanceof UniqueConstraintViolationException) {
                                throw $e;
                            }

                            // Lost the race against a concurrent submit (double
                            // click, second tab) between the check above and the
                            // insert. The failed flush closes the EntityManager,
                            // so the owner cannot be re-read here — resolve it in
                            // a fresh request instead. /app/domain-taken sends a
                            // same-team conflict on to the domain itself.
                            return $this->redirectToRoute('domain_taken', ['domain' => $data->domainName]);
                        }

                        return $this->redirectToRoute('dashboard_domain_detail', ['id' => $domainId]);
                    }
                }
            }
        }

        return $this->render('dashboard/domain_add.html.twig', [
            'data' => $data,
            'errors' => $errors,
            'alreadyConnected' => $alreadyConnected,
            'canAddDomain' => $canAdd,
            'currentPlan' => $plan,
            'maxDomains' => $this->planLimits->getMaxDomains($plan),
            'domainCount' => $this->planEnforcement->getDomainCount($teamId->toString()),
            'targetTeam' => $this->teamRepository->get($teamId),
        ]);
    }
}
