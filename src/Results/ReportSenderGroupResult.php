<?php

declare(strict_types=1);

namespace App\Results;

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
    ) {
    }

    /**
     * @param array{group_key: string, display_label: string, total_messages: int|string, dkim_pass_count: int|string, spf_pass_count: int|string, disposition_none: int|string, disposition_quarantine: int|string, disposition_reject: int|string, source_ips: string, sender_is_authorized: int|string|null} $row
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
