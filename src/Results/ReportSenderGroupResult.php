<?php

declare(strict_types=1);

namespace App\Results;

use App\Value\ForwardingAttestation;
use App\Value\SenderReviewState;
use App\Value\SenderRole;

/**
 * One sender on the report-detail "By sender" pane.
 *
 * A row is a sender identity, so {@see $sourceIps} routinely holds several
 * addresses — a rotating relay pool or a gateway's regional nodes.
 */
final readonly class ReportSenderGroupResult
{
    /**
     * @param array<string> $sourceIps
     */
    public function __construct(
        public string $groupKey,
        public string $displayLabel,
        public int $totalMessages,
        public int $dkimPassCount,
        public float $dkimPassRate,
        public int $spfPassCount,
        public float $spfPassRate,
        public int $dispositionNone,
        public int $dispositionQuarantine,
        public int $dispositionReject,
        public array $sourceIps,
        public ?bool $senderIsAuthorized,
        /**
         * The group's worst review state, so this pane and the Sender Inventory
         * can never render two different verdicts for the same server. Null when
         * no inventory row backs the group yet. Kept alongside
         * $senderIsAuthorized because the report-authorization display still
         * asks the plain "is this one authorised?" question.
         */
        public ?SenderReviewState $reviewState = null,
        /**
         * What this sender is, independent of how its mail authenticated in
         * this one report. A forwarder failing SPF is the expected outcome of
         * forwarding, not evidence of anything wrong; without the role the
         * pane cannot tell the reader the difference. Null when no address in
         * the group has been classified yet.
         */
        public ?SenderRole $senderRole = null,
        /**
         * What the receiver said about its own handling of this mail
         * (DEC-060 tier B). Kept beside $senderRole rather than folded into it:
         * the role is the globally cached fact about the host, this is one
         * receiver's account of one report, and collapsing them would cache a
         * per-report observation as a permanent property of the sender.
         */
        public ForwardingAttestation $forwarding = new ForwardingAttestation(),
    ) {
    }

    /**
     * @param array{group_key: string, display_label: string, sender_role: string|null, total_messages: int|string, dkim_pass_count: int|string, spf_pass_count: int|string, disposition_none: int|string, disposition_quarantine: int|string, disposition_reject: int|string, source_ips: string, sender_is_authorized: int|string|null, known_sender_count: int|string, needs_review_sender_count: int|string, authorized_sender_count: int|string, policy_override_reasons: string|null} $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        $totalMessages = (int) $row['total_messages'];
        $dkimPassCount = (int) $row['dkim_pass_count'];
        $spfPassCount = (int) $row['spf_pass_count'];

        return new self(
            groupKey: $row['group_key'],
            displayLabel: $row['display_label'],
            totalMessages: $totalMessages,
            dkimPassCount: $dkimPassCount,
            dkimPassRate: $totalMessages > 0 ? round($dkimPassCount / $totalMessages * 100, 1) : 0.0,
            spfPassCount: $spfPassCount,
            spfPassRate: $totalMessages > 0 ? round($spfPassCount / $totalMessages * 100, 1) : 0.0,
            dispositionNone: (int) $row['disposition_none'],
            dispositionQuarantine: (int) $row['disposition_quarantine'],
            dispositionReject: (int) $row['disposition_reject'],
            sourceIps: self::parsePgArray($row['source_ips']),
            senderIsAuthorized: null !== $row['sender_is_authorized']
                ? (bool) (int) $row['sender_is_authorized']
                : null,
            reviewState: SenderReviewState::worstOfGroup(
                knownSenderCount: (int) $row['known_sender_count'],
                needsReviewCount: (int) $row['needs_review_sender_count'],
                authorizedCount: (int) $row['authorized_sender_count'],
            ),
            senderRole: null !== $row['sender_role'] ? SenderRole::from($row['sender_role']) : null,
            forwarding: ForwardingAttestation::fromAggregatedJson($row['policy_override_reasons']),
        );
    }

    /**
     * @return array<string>
     */
    private static function parsePgArray(string $literal): array
    {
        $inner = trim($literal, '{}');
        if ('' === $inner) {
            return [];
        }

        return array_values(array_filter(
            explode(',', $inner),
            static fn (string $v): bool => '' !== $v && 'NULL' !== $v,
        ));
    }
}
