<?php

declare(strict_types=1);

namespace App\Services;

use App\Entity\MailboxConnection;
use App\Results\Dns\RuaScenarioResult;
use App\Results\MailboxActivitySummary;
use App\Results\MailboxHealthAdvisorResult;
use App\Value\Dns\RuaScenario;
use App\Value\MailboxHealthSeverity;
use Psr\Clock\ClockInterface;

/**
 * Picks the per-mailbox health advisory shown above the connection-details
 * card on `/app/mailboxes/{id}` (TASK-094). Pure deterministic computation
 * over the {@see MailboxConnection} entity (for lastError / lastPolledAt /
 * createdAt / isActive) plus a 30-day {@see MailboxActivitySummary} aggregate
 * (envelopes + quarantine counts).
 *
 * Branch precedence is deliberate and locked by tests:
 *  1. `broken_credentials` — anytime `lastError` is set. The eligibility line
 *     in the TASK-094 spec mentions a 24h `lastPolledAt` gate as the trigger,
 *     but the same spec's edge-case section requires a 3-day-old lastError to
 *     ALSO flag broken_credentials. The 24h gate would otherwise leave the
 *     3-day-old case classified as healthy, which is user-hostile (a stale
 *     login error has not "self-healed" — it's just stale). Resolved in favour
 *     of the edge-case requirement: any `lastError !== null` fires this branch.
 *  2. `quarantine_dominant` — checked before silent_for_too_long because a
 *     mailbox can simultaneously have envelopes30d === 0 (no, that's not the
 *     case here — quarantine_dominant requires >= 10 envelopes, so the two
 *     are disjoint). Order matters only for branches that could co-fire;
 *     between these two it doesn't, but listing them in priority order keeps
 *     the intent obvious to future readers.
 *  3. `silent_for_too_long` — the catch-all for a healthy-looking mailbox
 *     that's mysteriously quiet. Requires 7+ days of polling history so a
 *     freshly connected mailbox doesn't immediately scream "broken" before
 *     the first poll cycle has had a chance to land a report.
 *
 * Returns null for the healthy case so the template can render with a single
 * `{% if advice %}` guard instead of branching on a sentinel severity.
 */
final readonly class MailboxHealthAdvisor
{
    public function __construct(
        private ClockInterface $clock,
        private ReportAddressProvider $reportAddressProvider,
    ) {
    }

    public function advise(
        MailboxConnection $mailbox,
        MailboxActivitySummary $activity,
        ?RuaScenarioResult $ruaScenarioForLinkedDomain = null,
    ): ?MailboxHealthAdvisorResult {
        if (null !== $mailbox->lastError) {
            return $this->brokenCredentials($mailbox, $ruaScenarioForLinkedDomain);
        }

        if ($this->isQuarantineDominant($activity)) {
            return $this->quarantineDominant($mailbox, $ruaScenarioForLinkedDomain);
        }

        if ($this->isSilentForTooLong($mailbox, $activity)) {
            return $this->silentForTooLong($mailbox, $ruaScenarioForLinkedDomain);
        }

        return null;
    }

    /**
     * Returns a "this mailbox is redundant" sentence to append to the reason
     * text when the linked domain's DMARC already routes to Sendvery (TASK-104).
     * The operator should know they don't need to spend time fixing this
     * mailbox at all — the credentials/quarantine issue here is moot since
     * reports flow via DNS. Empty string when no scenario / not redundant.
     */
    private function redundancyHint(MailboxConnection $mailbox, ?RuaScenarioResult $ruaScenarioForLinkedDomain): string
    {
        if (null === $ruaScenarioForLinkedDomain || null === $mailbox->monitoredDomain) {
            return '';
        }

        if (RuaScenario::PointsAtSendvery !== $ruaScenarioForLinkedDomain->scenario) {
            return '';
        }

        return sprintf(
            ' Heads-up: %s already routes reports to Sendvery via DNS, so this mailbox is redundant — you can disconnect it instead of fixing it.',
            $mailbox->monitoredDomain->domain,
        );
    }

    private function brokenCredentials(MailboxConnection $mailbox, ?RuaScenarioResult $ruaScenarioForLinkedDomain): MailboxHealthAdvisorResult
    {
        // lastPolledAt CAN be null if the very first poll attempt errored out
        // before stamping it — fall back to "recently" copy in that case so
        // we never render the literal "null" string.
        $polledAtLabel = null !== $mailbox->lastPolledAt
            ? $mailbox->lastPolledAt->format('M j, H:i')
            : 'the most recent attempt';

        return new MailboxHealthAdvisorResult(
            severity: MailboxHealthSeverity::BrokenCredentials,
            reasonText: sprintf(
                "Sendvery couldn't log into this mailbox at %s: %s. Re-test the connection or update credentials.%s",
                $polledAtLabel,
                $mailbox->lastError ?? '',
                $this->redundancyHint($mailbox, $ruaScenarioForLinkedDomain),
            ),
            primaryActionLabel: 'Re-test connection',
            primaryActionRoute: 'dashboard_mailbox_retest',
            primaryActionRouteParams: ['id' => $mailbox->id->toString()],
        );
    }

    private function isQuarantineDominant(MailboxActivitySummary $activity): bool
    {
        // The >=10 floor prevents a single quarantine on a barely-used mailbox
        // from screaming "more than half are quarantined" — fine when it's
        // literally 6/10, much less useful at 1/1.
        if ($activity->envelopes30d < 10) {
            return false;
        }

        return $activity->quarantined30d > $activity->envelopes30d * 0.5;
    }

    private function quarantineDominant(MailboxConnection $mailbox, ?RuaScenarioResult $ruaScenarioForLinkedDomain): MailboxHealthAdvisorResult
    {
        return new MailboxHealthAdvisorResult(
            severity: MailboxHealthSeverity::QuarantineDominant,
            reasonText: sprintf(
                'More than half of the envelopes this mailbox pulled in the last 30 days landed in quarantine. Usually means receivers are sending reports for domains you haven\'t added yet, or domains that aren\'t verified.%s',
                $this->redundancyHint($mailbox, $ruaScenarioForLinkedDomain),
            ),
            primaryActionLabel: 'Open quarantine for this mailbox',
            primaryActionRoute: 'dashboard_quarantine',
            primaryActionRouteParams: ['mailbox' => $mailbox->id->toString()],
        );
    }

    private function isSilentForTooLong(MailboxConnection $mailbox, MailboxActivitySummary $activity): bool
    {
        if (!$mailbox->isActive) {
            return false;
        }

        if (0 !== $activity->envelopes30d) {
            return false;
        }

        $sevenDaysAgo = $this->clock->now()->modify('-7 days');

        return $mailbox->createdAt < $sevenDaysAgo;
    }

    private function silentForTooLong(
        MailboxConnection $mailbox,
        ?RuaScenarioResult $ruaScenarioForLinkedDomain,
    ): MailboxHealthAdvisorResult {
        $reportAddress = $this->reportAddressProvider->get();

        // If the mailbox is bound to a single domain and that domain's
        // published rua= already points at an external address, name it in
        // the copy so the operator can see at-a-glance whether their DNS and
        // their mailbox tell the same story. Keep the generic fallback when
        // no scenario is available (team-shared mailboxes, or a domain whose
        // DMARC record we haven't yet checked).
        $scenarioSentence = $this->buildScenarioSentence($mailbox, $ruaScenarioForLinkedDomain, $reportAddress);

        $reasonText = sprintf(
            'Sendvery has polled this mailbox for the last 7+ days without finding any new DMARC reports. The credentials work but no reports are arriving — usually because the domain\'s rua= tag doesn\'t point at this inbox.%s Check DNS, or switch to DNS-based ingestion via %s.',
            $scenarioSentence,
            $reportAddress,
        );

        return new MailboxHealthAdvisorResult(
            severity: MailboxHealthSeverity::SilentForTooLong,
            reasonText: $reasonText,
            primaryActionLabel: 'Check DNS',
            primaryActionRoute: 'dashboard_dns_health',
            primaryActionRouteParams: [],
            secondaryActionLabel: 'Use DNS-based ingestion instead',
            secondaryActionRoute: 'dashboard_mailboxes',
            secondaryActionRouteParams: [],
        );
    }

    private function buildScenarioSentence(
        MailboxConnection $mailbox,
        ?RuaScenarioResult $ruaScenarioForLinkedDomain,
        string $reportAddress,
    ): string {
        if (null === $ruaScenarioForLinkedDomain || null === $mailbox->monitoredDomain) {
            return '';
        }

        return match ($ruaScenarioForLinkedDomain->scenario) {
            RuaScenario::PointsAtExternal => sprintf(
                ' Your domain %s currently sends reports to %s — connect that inbox instead, or repoint DMARC to %s.',
                $mailbox->monitoredDomain->domain,
                $ruaScenarioForLinkedDomain->ruaEmail ?? 'an external address',
                $reportAddress,
            ),
            RuaScenario::NoRecord => sprintf(
                ' Your domain %s has no rua= tag published yet — publishing one is the prerequisite for any inbox to receive reports.',
                $mailbox->monitoredDomain->domain,
            ),
            RuaScenario::PointsAtSendvery => sprintf(
                ' Your domain %s already routes reports to Sendvery\'s central inbox — this private mailbox is redundant and can be disconnected.',
                $mailbox->monitoredDomain->domain,
            ),
        };
    }
}
