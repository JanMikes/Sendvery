<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * Deterministic cache keys for {@see \App\Entity\AiInsight}. Reports are
 * immutable so report/anomaly keys are stable forever; the digest key rolls per
 * ISO week; the remediation key folds the failure details so a *different*
 * failure narrates anew while an identical re-check is a hit.
 */
final readonly class AiInsightCacheKey
{
    public static function reportExplanation(string $reportId): string
    {
        return 'report_explanation:'.$reportId;
    }

    public static function anomalyExplanation(string $reportId): string
    {
        return 'anomaly_explanation:'.$reportId;
    }

    public static function weeklyDigest(string $teamId, \DateTimeImmutable $now): string
    {
        // ISO-8601 year-week, e.g. "2026-W22" — one digest per team per week.
        return 'weekly_digest:'.$teamId.':'.$now->format('o-\WW');
    }

    public static function remediation(string $domainId, string $recordType): string
    {
        // Keyed on (domain, record type) only — stable across re-checks whose
        // issue wording drifts, so guidance is generated once per failing record.
        return 'remediation:'.$domainId.':'.strtoupper($recordType);
    }

    public static function senderLabel(string $ip, string $domain): string
    {
        return 'sender_label:'.$ip.':'.strtolower($domain);
    }
}
