<?php

declare(strict_types=1);

namespace App\Services;

use App\Query\GetDomainIngestionMatrix;
use App\Repository\MailboxConnectionRepository;
use App\Results\DomainIngestionMatrixResult;
use App\Services\Dns\RuaMailboxMatcher;
use App\Services\Dns\RuaScenarioResolver;
use App\Value\Dns\RuaScenario;
use App\Value\IngestionPath;
use Ramsey\Uuid\Uuid;

/**
 * Thin testable wrapper around {@see GetDomainIngestionMatrix}. Lives as a
 * service so controllers autowire a single typed entry point and future
 * adjustments (logging, eligibility-aware filtering, etc.) have a home
 * without rippling through call sites.
 */
final readonly class IngestionPathResolver
{
    public function __construct(
        private GetDomainIngestionMatrix $query,
        private RuaScenarioResolver $ruaScenarioResolver,
        private MailboxConnectionRepository $mailboxConnectionRepository,
        private RuaMailboxMatcher $ruaMailboxMatcher,
    ) {
    }

    /**
     * @param list<string> $teamIds team UUIDs the caller is allowed to read from
     *
     * @return list<DomainIngestionMatrixResult>
     */
    public function resolveForTeams(array $teamIds): array
    {
        $rows = $this->query->forTeams($teamIds);

        // TASK-100: enrich each matrix row with the RUA scenario derived from
        // the latest stored DMARC check so the template can render scenario-
        // aware badges + action CTAs without a second round of queries from
        // Twig.
        //
        // TASK-134: scenarios resolve in a single batch query rather than the
        // per-row foreach this method used to run — the old N+1 was the
        // primary contributor to the ingestion-matrix page's hot path latency
        // (~one `dns_check_result` lookup per matrix row) and would have
        // compounded with TASK-129's second N+1 on the dashboard overview.
        //
        // TASK-106: ALSO compute `pathMatchesMailbox` for rows where the
        // user's connected mailbox is the actual destination of the published
        // `rua=` tag. Lets the template render the path-honest "Ingesting via
        // mailbox" badge for the common operator-wired-it-right shape and
        // keep the scenario warning only for the genuinely-misconfigured
        // shape (wrong inbox connected).
        $scenarios = $this->ruaScenarioResolver->resolveForDomainIds(
            array_values(array_map(static fn (DomainIngestionMatrixResult $row): string => $row->domainId, $rows)),
        );

        return array_values(array_map(
            function (DomainIngestionMatrixResult $row) use ($scenarios): DomainIngestionMatrixResult {
                $withScenario = $row->withScenario($scenarios[$row->domainId]);

                return $withScenario->withPathMatchesMailbox(
                    $this->computePathMatchesMailbox($withScenario),
                );
            },
            $rows,
        ));
    }

    /**
     * True when EVERY row in the supplied matrix is in scenario
     * {@see RuaScenario::PointsAtSendvery}. Drives the TASK-105 collapse of
     * the `IngestionRoutesCallout` two-card layout into a single confirmation
     * card — an all-scenario-(b) team has already decided, the fallback CTA
     * is noise. False for an empty matrix (a brand-new team has no scenario
     * to read from yet and still wants the educational two-card layout).
     *
     * @param list<DomainIngestionMatrixResult> $matrix
     */
    public function allScenarioPointsAtSendvery(array $matrix): bool
    {
        if ([] === $matrix) {
            return false;
        }

        foreach ($matrix as $row) {
            if (null === $row->ruaScenario) {
                return false;
            }

            if (RuaScenario::PointsAtSendvery !== $row->ruaScenario->scenario) {
                return false;
            }
        }

        return true;
    }

    /**
     * The four preconditions for TASK-106's path-vs-scenario flip:
     *   1. The path classifier says reports are physically arriving via the
     *      connected mailbox (not DNS, not nothing).
     *   2. `lastReportAt` is populated — without it the path-vs-scenario
     *      priority isn't load-bearing and the template won't render the
     *      badge anyway. Short-circuiting here also skips the expensive
     *      mailbox lookup + decrypt for never-polled rows.
     *   3. The RUA scenario says DMARC routes to an external (non-Sendvery)
     *      address — without this, the regular DNS/mailbox branches handle it.
     *   4. The connected mailbox's login matches the external rua= address.
     *      Loose match: strip any `mailto:` prefix (already handled by
     *      DmarcRecordParser), lowercase both sides, exact local-part@domain
     *      equality. Anything looser (alias forwarding, plus-tagging) is
     *      deliberately out of scope for v1.
     *
     * Steps 1-3 are this method's guard preconditions; step 4 delegates to
     * {@see RuaMailboxMatcher} so the DomainSetupStatusResolver shares the
     * exact same matching rule.
     *
     * Returns false on any missing input — defensive so a partially-populated
     * row never accidentally flips the badge.
     */
    private function computePathMatchesMailbox(DomainIngestionMatrixResult $row): bool
    {
        if (IngestionPath::Mailbox !== $row->path) {
            return false;
        }

        if (null === $row->lastReportAt) {
            return false;
        }

        if (null === $row->ruaScenario) {
            return false;
        }

        if (RuaScenario::PointsAtExternal !== $row->ruaScenario->scenario) {
            return false;
        }

        if (null === $row->mailboxId) {
            return false;
        }

        // The matrix row already names the mailbox UUID — go through the
        // single-mailbox variant on the matcher rather than re-deriving the
        // binding from $row->domainId. That keeps the per-row work O(1) on
        // mailbox lookups even when a domain has multiple connected mailboxes.
        try {
            $mailbox = $this->mailboxConnectionRepository->get(Uuid::fromString($row->mailboxId));
        } catch (\App\Exceptions\MailboxConnectionNotFound) {
            return false;
        }

        return $this->ruaMailboxMatcher->matchesMailbox($mailbox, $row->ruaScenario->ruaEmail);
    }
}
