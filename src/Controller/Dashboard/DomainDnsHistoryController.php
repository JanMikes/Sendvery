<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Query\GetDomainDetail;
use App\Query\GetDomainDnsHistory;
use App\Query\GetDomainWorkspaceTabCounts;
use App\Repository\DnsCheckResultRepository;
use App\Results\Dns\DnsRecordDiff;
use App\Results\DnsCheckHistoryResult;
use App\Services\DashboardContext;
use App\Services\Dns\DnsRecordDiffer;
use App\Services\ReportAddressProvider;
use App\Value\Dns\DmarcRuaInstruction;
use App\Value\DnsCheckType;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DomainDnsHistoryController extends AbstractController
{
    private const ALLOWED_RANGE_DAYS = [7, 30, 90, 0];
    private const DEFAULT_RANGE_DAYS = 30;

    public function __construct(
        private readonly DashboardContext $dashboardContext,
        private readonly GetDomainDetail $getDomainDetail,
        private readonly GetDomainDnsHistory $getDomainDnsHistory,
        private readonly DnsCheckResultRepository $dnsCheckResultRepository,
        private readonly ReportAddressProvider $reportAddressProvider,
        private readonly ClockInterface $clock,
        private readonly GetDomainWorkspaceTabCounts $getDomainWorkspaceTabCounts,
        private readonly DnsRecordDiffer $dnsRecordDiffer,
    ) {
    }

    #[Route('/app/domains/{id}/dns-history', name: 'dashboard_domain_dns_history')]
    public function __invoke(string $id, Request $request): Response
    {
        $teamIds = $this->dashboardContext->getTeamIdStrings();
        $domain = $this->getDomainDetail->forDomain($id, $teamIds);

        if (null === $domain) {
            throw $this->createNotFoundException('Domain not found.');
        }

        $activeType = DnsCheckType::tryFrom((string) $request->query->get('type', ''));

        $requestedRange = $request->query->getInt('range', self::DEFAULT_RANGE_DAYS);
        $rangeDays = in_array($requestedRange, self::ALLOWED_RANGE_DAYS, true)
            ? $requestedRange
            : self::DEFAULT_RANGE_DAYS;

        $changesOnly = $request->query->getBoolean('changes_only');

        $history = $this->getDomainDnsHistory->forDomain(
            domainId: $id,
            teamIds: $teamIds,
            type: $activeType,
            rangeDays: $rangeDays,
            changesOnly: $changesOnly,
        );

        $changesOnlyCount = $this->getDomainDnsHistory->countChanges(
            domainId: $id,
            teamIds: $teamIds,
            type: $activeType,
            rangeDays: $rangeDays,
        );

        $hasAnyHistory = $this->getDomainDnsHistory->hasAnyHistory($id, $teamIds);

        $groupedHistory = $this->groupByDate($history);
        $openDays = $this->computeOpenDays($groupedHistory, $activeType);
        $diffsById = $this->buildDiffs($history);

        $latestDmarcCheck = $this->dnsCheckResultRepository->findLatestForDomainAndType(
            Uuid::fromString($id),
            DnsCheckType::Dmarc,
        );

        $ruaInstruction = DmarcRuaInstruction::build(
            $latestDmarcCheck?->rawRecord,
            $this->reportAddressProvider->get(),
        );

        $tabCounts = $this->getDomainWorkspaceTabCounts->forDomain($id)->toTwigArray();

        return $this->render('dashboard/domain_dns_history.html.twig', [
            'domain' => $domain,
            'groupedHistory' => $groupedHistory,
            'openDays' => $openDays,
            'activeType' => $activeType,
            'rangeDays' => $rangeDays,
            'changesOnly' => $changesOnly,
            'changesOnlyCount' => $changesOnlyCount,
            'hasAnyHistory' => $hasAnyHistory,
            'ruaInstruction' => $ruaInstruction,
            'tabCounts' => $tabCounts,
            'diffsById' => $diffsById,
        ]);
    }

    /**
     * Pre-compute the token-level diff for every CHANGED row up front so the
     * template doesn't need a Twig extension just to call the differ.
     * Initial-check rows (no prior observation) are excluded — the template
     * already gates the diff rendering on `isRealChange`.
     *
     * @param array<DnsCheckHistoryResult> $history
     *
     * @return array<string, DnsRecordDiff>
     */
    private function buildDiffs(array $history): array
    {
        $diffs = [];
        foreach ($history as $check) {
            if (!$check->isRealChange()) {
                continue;
            }

            $type = DnsCheckType::tryFrom($check->type);
            if (null === $type) {
                continue;
            }

            $diffs[$check->id] = $this->dnsRecordDiffer->diff(
                $type,
                $check->previousRawRecord,
                $check->rawRecord,
            );
        }

        return $diffs;
    }

    /**
     * @param array<DnsCheckHistoryResult> $history
     *
     * @return array<string, array{checks: list<DnsCheckHistoryResult>, changeCount: int}>
     */
    private function groupByDate(array $history): array
    {
        $grouped = [];

        foreach ($history as $check) {
            // `checkedAt` is a string in the YYYY-MM-DD HH:MM:SS shape per
            // DnsCheckHistoryResult — substring is cheaper than parsing.
            $date = substr($check->checkedAt, 0, 10);

            if (!isset($grouped[$date])) {
                $grouped[$date] = ['checks' => [], 'changeCount' => 0];
            }

            $grouped[$date]['checks'][] = $check;

            // Initial-check rows are baselines, not changes — exclude them
            // from the day-level "N change(s)" badge.
            if ($check->isRealChange()) {
                ++$grouped[$date]['changeCount'];
            }
        }

        return $grouped;
    }

    /**
     * @param array<string, array{checks: list<DnsCheckHistoryResult>, changeCount: int}> $groupedHistory
     *
     * @return list<string>
     */
    private function computeOpenDays(array $groupedHistory, ?DnsCheckType $activeType): array
    {
        if (null !== $activeType) {
            return array_keys($groupedHistory);
        }

        $today = $this->clock->now()->format('Y-m-d');
        $open = [];
        $dates = array_keys($groupedHistory);

        foreach ($dates as $index => $date) {
            if ($date === $today
                || $groupedHistory[$date]['changeCount'] > 0
                || $index < 3
            ) {
                $open[] = $date;
            }
        }

        return $open;
    }
}
