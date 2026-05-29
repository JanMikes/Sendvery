<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Entity\AiInsight;
use App\Entity\Team;
use App\Repository\AiInsightRepository;
use App\Repository\MonitoredDomainRepository;
use App\Repository\TeamRepository;
use App\Services\Ai\Input\DnsCheckFailure;
use App\Services\Ai\Result\AnomalyExplanationResult;
use App\Services\Ai\Result\OnDemandExplanationResult;
use App\Services\Ai\Result\RemediationResult;
use App\Services\Ai\Result\SenderLabelResult;
use App\Services\Ai\Result\WeeklyDigestResult;
use App\Services\IdentityProvider;
use App\Value\AiInsightType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\UuidInterface;

/**
 * Durable cache for AI insights, placed OUTSIDE the plan/quota gate
 * (alias → Caching → PlanGated → Anthropic). A cache hit returns before the
 * gate is ever entered, so re-views of an immutable report cost nothing and
 * burn no on-demand quota; a miss descends into the gate (plan + quota checks)
 * and the real API call, then persists the result.
 *
 * It flushes the insight itself: the decorator runs from HTTP controllers (no
 * doctrine_transaction middleware) as well as message handlers (middleware
 * present), and the cache row must be written in both. Inside a handler the
 * flush runs within the already-open transaction, preserving atomicity.
 */
final readonly class CachingAiInsightsService implements AiInsightsService
{
    public function __construct(
        private AiInsightsService $inner,
        private AiInsightRepository $insights,
        private EntityManagerInterface $entityManager,
        private IdentityProvider $identityProvider,
        private ClockInterface $clock,
        private TeamRepository $teams,
        private MonitoredDomainRepository $domains,
    ) {
    }

    public function explainReport(UuidInterface $reportId, UuidInterface $teamId): OnDemandExplanationResult
    {
        $key = AiInsightCacheKey::reportExplanation($reportId->toString());
        $cached = $this->insights->findByCacheKey($key);
        if (null !== $cached) {
            return AiInsightContent::reportExplanation($cached->content);
        }

        $result = $this->inner->explainReport($reportId, $teamId);
        $this->store(AiInsightType::ReportExplanation, $reportId->toString(), $key, $result, $this->teams->get($teamId));

        return $result;
    }

    public function explainAnomaly(UuidInterface $reportId, UuidInterface $teamId): AnomalyExplanationResult
    {
        $key = AiInsightCacheKey::anomalyExplanation($reportId->toString());
        $cached = $this->insights->findByCacheKey($key);
        if (null !== $cached) {
            return AiInsightContent::anomaly($cached->content);
        }

        $result = $this->inner->explainAnomaly($reportId, $teamId);
        $this->store(AiInsightType::AnomalyExplanation, $reportId->toString(), $key, $result, $this->teams->get($teamId));

        return $result;
    }

    public function generateWeeklyDigest(UuidInterface $teamId): WeeklyDigestResult
    {
        $key = AiInsightCacheKey::weeklyDigest($teamId->toString(), $this->clock->now());
        $cached = $this->insights->findByCacheKey($key);
        if (null !== $cached) {
            return AiInsightContent::weeklyDigest($cached->content);
        }

        $result = $this->inner->generateWeeklyDigest($teamId);
        $this->store(AiInsightType::WeeklyDigest, $teamId->toString(), $key, $result, $this->teams->get($teamId));

        return $result;
    }

    public function generateRemediationGuidance(UuidInterface $domainId, DnsCheckFailure $failure): RemediationResult
    {
        $key = AiInsightCacheKey::remediation($domainId->toString(), $failure->recordType);
        $cached = $this->insights->findByCacheKey($key);
        if (null !== $cached) {
            return AiInsightContent::remediation($cached->content);
        }

        $result = $this->inner->generateRemediationGuidance($domainId, $failure);
        $this->store(AiInsightType::Remediation, $domainId->toString(), $key, $result, $this->domains->get($domainId)->team);

        return $result;
    }

    public function labelSender(string $ip, string $domain): SenderLabelResult
    {
        $key = AiInsightCacheKey::senderLabel($ip, $domain);
        $cached = $this->insights->findByCacheKey($key);
        if (null !== $cached) {
            return AiInsightContent::senderLabel($cached->content);
        }

        $result = $this->inner->labelSender($ip, $domain);
        // Sender labels are global (IP+domain), not team-scoped.
        $this->store(AiInsightType::SenderLabel, $ip, $key, $result, null);

        return $result;
    }

    private function store(AiInsightType $type, string $subjectId, string $cacheKey, object $result, ?Team $team): void
    {
        // Re-check after the (possibly slow) inner call: a concurrent writer may
        // have inserted this key meanwhile. Skipping keeps the unique constraint
        // from aborting the whole request — a duplicate generation is at worst
        // one wasted call, never a double-charge (quota lives below this layer).
        if (null !== $this->insights->findByCacheKey($cacheKey)) {
            return;
        }

        $this->entityManager->persist(new AiInsight(
            id: $this->identityProvider->nextIdentity(),
            team: $team,
            type: $type,
            subjectId: $subjectId,
            cacheKey: $cacheKey,
            content: AiInsightContent::encode($result),
            createdAt: $this->clock->now(),
        ));
        $this->entityManager->flush();
    }
}
