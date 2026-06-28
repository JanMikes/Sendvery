<?php

declare(strict_types=1);

namespace App\Controller\Onboarding;

use App\Entity\User;
use App\Message\ConnectMailbox;
use App\Message\EnableManagedDmarc;
use App\Query\GetTeamPlan;
use App\Repository\MonitoredDomainRepository;
use App\Services\Dns\CloudflareDnsClient;
use App\Services\Dns\DmarcChecker;
use App\Services\Dns\ManagedDmarcCnameChecker;
use App\Services\Dns\RuaScenarioResolver;
use App\Services\IdentityProvider;
use App\Services\ReportAddressProvider;
use App\Services\Stripe\PlanEnforcement;
use App\Services\TeamProvisioner;
use App\Value\Dns\DmarcRuaInstruction;
use App\Value\Dns\DmarcSetupMode;
use App\Value\Dns\RuaScenario;
use App\Value\MailboxEncryption;
use App\Value\MailboxType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

final class OnboardingIngestionController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly IdentityProvider $identityProvider,
        private readonly TeamProvisioner $teamProvisioner,
        private readonly MonitoredDomainRepository $monitoredDomainRepository,
        private readonly DmarcChecker $dmarcChecker,
        private readonly ReportAddressProvider $reportAddressProvider,
        private readonly RuaScenarioResolver $ruaScenarioResolver,
        private readonly CloudflareDnsClient $cloudflareClient,
        private readonly GetTeamPlan $getTeamPlan,
        private readonly PlanEnforcement $planEnforcement,
        private readonly ManagedDmarcCnameChecker $cnameChecker,
    ) {
    }

    #[Route('/app/onboarding/ingestion', name: 'onboarding_ingestion', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if (null !== $user->onboardingCompletedAt) {
            return $this->redirectToRoute('dashboard_overview');
        }

        $teamId = $this->teamProvisioner->provisionForUser($user)->id;

        // Block direct access to step 3 until the user has actually saved a domain in step 2.
        $primaryDomain = $this->monitoredDomainRepository->findLatestForTeam($teamId);
        if (null === $primaryDomain) {
            return $this->redirectToRoute('onboarding_domain');
        }

        $errors = [];
        $method = $request->request->getString('method');

        $managedAvailable = $this->cloudflareClient->isConfigured()
            && $this->planEnforcement->canUseManagedDmarc($this->getTeamPlan->forTeam($teamId->toString()));

        if ($request->isMethod('POST')) {
            if ('forward' === $method) {
                return $this->redirectToRoute('onboarding_complete');
            }

            if ('managed' === $method && $managedAvailable) {
                // Publish-first: enable hosts the policy record before the user
                // points the CNAME at us. Back on GET we render the CNAME
                // instruction + the three-state managed verify frame.
                $this->commandBus->dispatch(new EnableManagedDmarc(
                    domainId: $primaryDomain->id,
                    teamId: $teamId->toString(),
                    actorUserId: $user->id,
                ));

                return $this->redirectToRoute('onboarding_ingestion');
            }

            if ('mailbox' === $method) {
                $host = trim($request->request->getString('host'));
                $port = $request->request->getInt('port', 993);
                $username = trim($request->request->getString('username'));
                $password = $request->request->getString('password');
                $encryption = $request->request->getString('encryption', 'ssl');

                if ('' === $host || '' === $username || '' === $password) {
                    $errors[] = 'Please fill in all connection fields.';
                } else {
                    $this->commandBus->dispatch(new ConnectMailbox(
                        connectionId: $this->identityProvider->nextIdentity(),
                        teamId: $teamId,
                        domainId: null,
                        type: MailboxType::ImapUser,
                        host: $host,
                        port: $port,
                        username: $username,
                        password: $password,
                        encryption: MailboxEncryption::from($encryption),
                    ));

                    return $this->redirectToRoute('onboarding_complete');
                }
            }
        }

        $requestedDomain = strtolower(trim($request->query->getString('domain')));
        $explicitDomain = '' !== $requestedDomain
            ? $this->monitoredDomainRepository->findByDomain($requestedDomain, $teamId)
            : null;

        // The early-return guard above ensures the team has at least one domain.
        $primaryDomain = $explicitDomain ?? $primaryDomain;

        // TASK-096: skip the ingestion step entirely when the team's primary
        // domain already publishes a rua= tag that points at Sendvery — the
        // user is already done; making them re-confirm "I'll publish a record"
        // when the record is in place is the wrong shape of friction. Uses
        // RuaScenarioResolver from TASK-100 (reads the latest STORED DNS
        // check; no live lookup), which means the skip only fires once the
        // domain has been DNS-checked at least once. A brand-new domain stays
        // on this page until the next check tick — exactly the behaviour the
        // user expects ("show me what to do, then verify").
        if ($request->isMethod('GET')) {
            $scenario = $this->ruaScenarioResolver->resolveForDomain($primaryDomain);
            if (RuaScenario::PointsAtSendvery === $scenario->scenario) {
                return $this->redirectToRoute('onboarding_complete');
            }
        }

        $dmarcCheck = $this->dmarcChecker->check($primaryDomain->domain);

        $reportAddress = $this->reportAddressProvider->get();

        return $this->render('onboarding/ingestion.html.twig', [
            'errors' => $errors,
            'domainName' => $primaryDomain->domain,
            'ruaInstruction' => DmarcRuaInstruction::build($dmarcCheck->rawRecord, $reportAddress),
            'reportAddress' => $reportAddress,
            'managedDmarcAvailable' => $managedAvailable,
            'dnsAutomationConfigured' => $this->cloudflareClient->isConfigured(),
            'isManaged' => DmarcSetupMode::ManagedCname === $primaryDomain->dmarcSetupMode,
            'managedCnameTarget' => $this->cnameChecker->expectedTarget($primaryDomain->domain) ?? '',
        ]);
    }
}
