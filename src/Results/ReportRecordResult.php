<?php

declare(strict_types=1);

namespace App\Results;

use App\Value\SenderRole;

final readonly class ReportRecordResult
{
    public function __construct(
        public string $recordId,
        public string $sourceIp,
        public int $count,
        public string $disposition,
        public string $dkimResult,
        public string $spfResult,
        public string $headerFrom,
        public ?string $dkimDomain,
        public ?string $dkimSelector,
        public ?string $spfDomain,
        public ?string $resolvedHostname,
        public ?string $resolvedOrg,
        /**
         * What this address is, from the global identity cache. Null means the
         * address has no cache row yet — not "unknown sender", which is a
         * classification we would have had to earn.
         */
        public ?SenderRole $senderRole = null,
    ) {
    }

    /** @param array{record_id: string, source_ip: string, count: int|string, disposition: string, dkim_result: string, spf_result: string, header_from: string, dkim_domain: string|null, dkim_selector: string|null, spf_domain: string|null, resolved_hostname: string|null, resolved_org: string|null, sender_role: string|null} $row */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            recordId: $row['record_id'],
            sourceIp: $row['source_ip'],
            count: (int) $row['count'],
            disposition: $row['disposition'],
            dkimResult: $row['dkim_result'],
            spfResult: $row['spf_result'],
            headerFrom: $row['header_from'],
            dkimDomain: $row['dkim_domain'],
            dkimSelector: $row['dkim_selector'],
            spfDomain: $row['spf_domain'],
            resolvedHostname: $row['resolved_hostname'],
            resolvedOrg: $row['resolved_org'],
            senderRole: null !== $row['sender_role'] ? SenderRole::from($row['sender_role']) : null,
        );
    }
}
