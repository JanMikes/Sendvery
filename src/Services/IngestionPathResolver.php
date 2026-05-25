<?php

declare(strict_types=1);

namespace App\Services;

use App\Query\GetDomainIngestionMatrix;
use App\Repository\MailboxConnectionRepository;
use App\Results\DomainIngestionMatrixResult;
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
        private CredentialEncryptor $credentialEncryptor,
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
        // Twig. TODO: this introduces one extra query per domain (N+1) —
        // acceptable for a typical team's <20 domains; batch lookup is a
        // future task.
        //
        // TASK-106: ALSO compute `pathMatchesMailbox` for rows where the
        // user's connected mailbox is the actual destination of the published
        // `rua=` tag. Lets the template render the path-honest "Ingesting via
        // mailbox" badge for the common operator-wired-it-right shape and
        // keep the scenario warning only for the genuinely-misconfigured
        // shape (wrong inbox connected).
        return array_values(array_map(
            function (DomainIngestionMatrixResult $row): DomainIngestionMatrixResult {
                $scenario = $this->ruaScenarioResolver->resolveForDomainId(Uuid::fromString($row->domainId));
                $withScenario = $row->withScenario($scenario);

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
     * The three preconditions for TASK-106's path-vs-scenario flip:
     *   1. The path classifier says reports are physically arriving via the
     *      connected mailbox (not DNS, not nothing).
     *   2. The RUA scenario says DMARC routes to an external (non-Sendvery)
     *      address — without this, the regular DNS/mailbox branches handle it.
     *   3. The connected mailbox's login matches the external rua= address.
     *      Loose match: strip any `mailto:` prefix (already handled by
     *      DmarcRecordParser), lowercase both sides, exact local-part@domain
     *      equality. Anything looser (alias forwarding, plus-tagging) is
     *      deliberately out of scope for v1.
     *
     * Returns false on any missing input — defensive so a partially-populated
     * row never accidentally flips the badge.
     */
    private function computePathMatchesMailbox(DomainIngestionMatrixResult $row): bool
    {
        if (IngestionPath::Mailbox !== $row->path) {
            return false;
        }

        // The DTO contract is "reports are physically arriving AND credentials
        // match" — without lastReportAt the path-vs-scenario priority isn't
        // load-bearing and the template won't render the badge anyway.
        if (null === $row->lastReportAt) {
            return false;
        }

        if (null === $row->ruaScenario) {
            return false;
        }

        if (RuaScenario::PointsAtExternal !== $row->ruaScenario->scenario) {
            return false;
        }

        if (null === $row->ruaScenario->ruaEmail || null === $row->mailboxId) {
            return false;
        }

        try {
            $mailbox = $this->mailboxConnectionRepository->get(Uuid::fromString($row->mailboxId));
        } catch (\App\Exceptions\MailboxConnectionNotFound) {
            return false;
        }

        try {
            $username = $this->credentialEncryptor->decrypt($mailbox->encryptedUsername);
        } catch (\RuntimeException) {
            // Decryption failure (corrupted ciphertext, key rotated, etc.)
            // shouldn't flip the badge — fall through to the conservative
            // scenario-warning rendering. The error surfaces elsewhere on the
            // mailbox row itself.
            return false;
        }

        return $this->emailsMatch($username, $row->ruaScenario->ruaEmail);
    }

    private function emailsMatch(string $a, string $b): bool
    {
        $a = strtolower(trim($a));
        $b = strtolower(trim($b));

        // Strip `mailto:` defensively even though DmarcRecordParser already
        // removes it — the rua email field on RuaScenarioResult is contractually
        // clean but it costs nothing to be robust against future regressions.
        if (str_starts_with($a, 'mailto:')) {
            $a = substr($a, 7);
        }
        if (str_starts_with($b, 'mailto:')) {
            $b = substr($b, 7);
        }

        // Both sides must look like an email — bail if either is missing an `@`
        // so we don't false-match on garbage usernames (e.g. a literal IMAP
        // username that isn't an email at all).
        if (false === strpos($a, '@') || false === strpos($b, '@')) {
            return false;
        }

        return $a === $b;
    }
}
