<?php

declare(strict_types=1);

namespace App\Services\Stripe;

use App\Value\SubscriptionPlan;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * Runtime checks for plan limits. Reads counts from the database; updates
 * usage counters atomically in team_usage and team_ai_usage.
 *
 * Period model: each counter row covers a monthly window. On the first
 * call within a new period (period_ends_at < now), the counter resets to
 * zero implicitly via `ensureCurrentPeriod()`. A nightly cron also
 * normalizes any teams that haven't been touched in a while.
 */
final readonly class PlanEnforcement
{
    public function __construct(
        private PlanLimits $planLimits,
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    // ─── Domain count ─────────────────────────────────────────────────────

    public function canAddDomain(string $teamId, SubscriptionPlan $plan): bool
    {
        return $this->getDomainCount($teamId) < $this->planLimits->getMaxDomains($plan);
    }

    public function getDomainCount(string $teamId): int
    {
        return (int) $this->database->executeQuery(
            'SELECT COUNT(*) FROM monitored_domain WHERE team_id = :teamId',
            ['teamId' => $teamId],
        )->fetchOne();
    }

    // ─── Team member count ────────────────────────────────────────────────

    public function canAddTeamMember(string $teamId, SubscriptionPlan $plan): bool
    {
        return $this->getTeamMemberCount($teamId) < $this->planLimits->getMaxTeamMembers($plan);
    }

    public function getTeamMemberCount(string $teamId): int
    {
        return (int) $this->database->executeQuery(
            'SELECT COUNT(*) FROM team_membership WHERE team_id = :teamId',
            ['teamId' => $teamId],
        )->fetchOne();
    }

    // ─── Feature gating ───────────────────────────────────────────────────

    public function canAccessFeature(SubscriptionPlan $plan, string $feature): bool
    {
        return $this->planLimits->hasFeature($plan, $feature);
    }

    // ─── Monthly report cap ───────────────────────────────────────────────

    public function canParseReport(string $teamId, SubscriptionPlan $plan): bool
    {
        return $this->getMonthlyReportCount($teamId) < $this->planLimits->getMaxReportsPerMonth($plan);
    }

    public function getMonthlyReportCount(string $teamId): int
    {
        $this->ensureCurrentPeriod('team_usage', 'reports_parsed_count', $teamId);

        return (int) $this->database->executeQuery(
            'SELECT reports_parsed_count FROM team_usage WHERE team_id = :teamId',
            ['teamId' => $teamId],
        )->fetchOne();
    }

    public function incrementMonthlyReportCount(string $teamId): void
    {
        $this->ensureCurrentPeriod('team_usage', 'reports_parsed_count', $teamId);

        $this->database->executeStatement(
            'UPDATE team_usage SET reports_parsed_count = reports_parsed_count + 1 WHERE team_id = :teamId',
            ['teamId' => $teamId],
        );
    }

    // ─── On-demand AI quota ───────────────────────────────────────────────

    public function canUseOnDemandAi(string $teamId, SubscriptionPlan $plan): bool
    {
        if (!$plan->hasAi()) {
            return false;
        }

        return $this->getOnDemandAiUsage($teamId) < $this->planLimits->getOnDemandAiQuota($plan);
    }

    public function getOnDemandAiUsage(string $teamId): int
    {
        $this->ensureCurrentPeriod('team_ai_usage', 'on_demand_count', $teamId);

        return (int) $this->database->executeQuery(
            'SELECT on_demand_count FROM team_ai_usage WHERE team_id = :teamId',
            ['teamId' => $teamId],
        )->fetchOne();
    }

    public function incrementOnDemandAiUsage(string $teamId): void
    {
        $this->ensureCurrentPeriod('team_ai_usage', 'on_demand_count', $teamId);

        $this->database->executeStatement(
            'UPDATE team_ai_usage SET on_demand_count = on_demand_count + 1 WHERE team_id = :teamId',
            ['teamId' => $teamId],
        );
    }

    public function getRemainingAiQuota(string $teamId, SubscriptionPlan $plan): int
    {
        if (!$plan->hasAi()) {
            return 0;
        }

        $limit = $this->planLimits->getOnDemandAiQuota($plan);
        $used = $this->getOnDemandAiUsage($teamId);

        return max(0, $limit - $used);
    }

    // ─── Period management ────────────────────────────────────────────────

    /**
     * Roll every expired usage row in `team_usage` and `team_ai_usage`
     * forward to the current month, zeroing its counter. Intended for the
     * `sendvery:usage:reset` cron — `ensureCurrentPeriod()` already does
     * this lazily on read/write, but the cron normalizes idle teams so
     * dashboards always show fresh "0 used this month" instead of
     * potentially-stale numbers from a yet-to-be-touched account.
     *
     * Returns the total number of rows reset across both tables.
     */
    public function resetExpiredCounters(): int
    {
        $now = $this->clock->now();
        $periodStart = $now->modify('first day of this month')->setTime(0, 0);
        $periodEnd = $periodStart->modify('+1 month');

        $params = [
            'start' => $periodStart->format('Y-m-d H:i:s'),
            'end' => $periodEnd->format('Y-m-d H:i:s'),
            'now' => $now->format('Y-m-d H:i:s'),
        ];

        $reportRows = $this->database->executeStatement(
            'UPDATE team_usage
             SET reports_parsed_count = 0,
                 period_started_at = :start,
                 period_ends_at = :end
             WHERE period_ends_at <= :now',
            $params,
        );

        $aiRows = $this->database->executeStatement(
            'UPDATE team_ai_usage
             SET on_demand_count = 0,
                 period_started_at = :start,
                 period_ends_at = :end
             WHERE period_ends_at <= :now',
            $params,
        );

        return (int) $reportRows + (int) $aiRows;
    }

    /**
     * Ensures the usage row exists and its monthly period is current.
     * Creates the row at zero if missing; resets the counter if the period
     * has expired. Idempotent — safe to call before every read or write.
     */
    private function ensureCurrentPeriod(string $table, string $counterColumn, string $teamId): void
    {
        $now = $this->clock->now();
        $periodStart = $now->modify('first day of this month')->setTime(0, 0);
        $periodEnd = $periodStart->modify('+1 month');

        $existing = $this->database->executeQuery(
            "SELECT period_ends_at FROM {$table} WHERE team_id = :teamId",
            ['teamId' => $teamId],
        )->fetchOne();

        if (false === $existing) {
            // Row missing: insert at zero.
            $this->database->executeStatement(
                "INSERT INTO {$table} (team_id, {$counterColumn}, period_started_at, period_ends_at)
                 VALUES (:teamId, 0, :start, :end)",
                [
                    'teamId' => $teamId,
                    'start' => $periodStart->format('Y-m-d H:i:s'),
                    'end' => $periodEnd->format('Y-m-d H:i:s'),
                ],
            );

            return;
        }

        $endsAt = new \DateTimeImmutable((string) $existing);
        if ($endsAt <= $now) {
            // Period expired: reset counter and roll the window forward.
            $this->database->executeStatement(
                "UPDATE {$table}
                 SET {$counterColumn} = 0,
                     period_started_at = :start,
                     period_ends_at = :end
                 WHERE team_id = :teamId",
                [
                    'teamId' => $teamId,
                    'start' => $periodStart->format('Y-m-d H:i:s'),
                    'end' => $periodEnd->format('Y-m-d H:i:s'),
                ],
            );
        }
    }
}
