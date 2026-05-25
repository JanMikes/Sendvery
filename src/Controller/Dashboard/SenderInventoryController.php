<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Query\GetDomainDetail;
use App\Query\GetDomainWorkspaceTabCounts;
use App\Query\GetSenderActivity30Day;
use App\Query\GetSenderInventory;
use App\Results\SenderActivity30Day;
use App\Services\DashboardContext;
use App\Services\SenderAuthorizationAdvisor;
use App\Value\SenderAdvisorSeverity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SenderInventoryController extends AbstractController
{
    public function __construct(
        private readonly DashboardContext $dashboardContext,
        private readonly GetDomainDetail $getDomainDetail,
        private readonly GetSenderInventory $getSenderInventory,
        private readonly GetSenderActivity30Day $getSenderActivity30Day,
        private readonly SenderAuthorizationAdvisor $senderAdvisor,
        private readonly GetDomainWorkspaceTabCounts $getDomainWorkspaceTabCounts,
    ) {
    }

    #[Route('/app/domains/{domainId}/senders', name: 'dashboard_sender_inventory', methods: ['GET'])]
    public function __invoke(string $domainId, Request $request): Response
    {
        $teamIds = $this->dashboardContext->getTeamIdStrings();
        $domain = $this->getDomainDetail->forDomain($domainId, $teamIds);

        if (null === $domain) {
            throw $this->createNotFoundException('Domain not found.');
        }

        $filterParam = $request->query->getString('filter');
        $recommendationParam = $request->query->getString('recommendation');

        $authorizedFilter = match ($filterParam) {
            'authorized' => true,
            'unauthorized' => false,
            default => null,
        };

        $senders = $this->getSenderInventory->forDomain($domainId, $teamIds, $authorizedFilter);

        $sourceIps = array_values(array_unique(array_map(static fn ($s) => $s->sourceIp, $senders)));
        $activityByIp = $this->getSenderActivity30Day->forDomain($domainId, $sourceIps);

        $advisorByIdMap = [];
        foreach ($senders as $sender) {
            $activity = $activityByIp[$sender->sourceIp] ?? SenderActivity30Day::empty();
            $advisorByIdMap[$sender->id] = $this->senderAdvisor->advise($sender, $activity);
        }

        $sortPriority = static fn (SenderAdvisorSeverity $severity): int => match ($severity) {
            SenderAdvisorSeverity::RecommendAuthorize, SenderAdvisorSeverity::RecommendRevoke => 0,
            SenderAdvisorSeverity::Monitor => 1,
            SenderAdvisorSeverity::None => 2,
        };

        usort($senders, static function ($a, $b) use ($advisorByIdMap, $sortPriority) {
            $aPrio = $sortPriority($advisorByIdMap[$a->id]->severity);
            $bPrio = $sortPriority($advisorByIdMap[$b->id]->severity);

            return $aPrio <=> $bPrio;
        });

        if ('needs_decision' === $recommendationParam) {
            $senders = array_values(array_filter(
                $senders,
                static fn ($s) => in_array(
                    $advisorByIdMap[$s->id]->severity,
                    [SenderAdvisorSeverity::RecommendAuthorize, SenderAdvisorSeverity::RecommendRevoke],
                    true,
                ),
            ));
        }

        $needsDecisionCount = 0;
        foreach ($advisorByIdMap as $result) {
            if (in_array($result->severity, [SenderAdvisorSeverity::RecommendAuthorize, SenderAdvisorSeverity::RecommendRevoke], true)) {
                ++$needsDecisionCount;
            }
        }

        $tabCounts = $this->getDomainWorkspaceTabCounts->forDomain($domainId)->toTwigArray();

        return $this->render('dashboard/sender_inventory.html.twig', [
            'domain' => $domain,
            'senders' => $senders,
            'filter' => $filterParam,
            'recommendationFilter' => $recommendationParam,
            'advisorBySenderId' => $advisorByIdMap,
            'needsDecisionCount' => $needsDecisionCount,
            'tabCounts' => $tabCounts,
        ]);
    }
}
