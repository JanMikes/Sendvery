<?php

declare(strict_types=1);

namespace App\Results;

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
    ) {
    }

    /** @param array{record_id: string, source_ip: string, count: int, disposition: string, dkim_result: string, spf_result: string, header_from: string, dkim_domain: ?string, dkim_selector: ?string, spf_domain: ?string, resolved_hostname: ?string, resolved_org: ?string} $row */
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
        );
    }
}
