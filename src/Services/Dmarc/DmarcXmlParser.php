<?php

declare(strict_types=1);

namespace App\Services\Dmarc;

use App\Exceptions\InvalidDmarcReportXml;
use App\Value\AuthResult;
use App\Value\Disposition;
use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;
use App\Value\ParsedDmarcRecord;
use App\Value\ParsedDmarcReport;
use App\Value\PolicyOverrideReason;
use App\Value\PolicyOverrideReasonType;

final readonly class DmarcXmlParser
{
    public function parse(string $xml): ParsedDmarcReport
    {
        $previousUseErrors = libxml_use_internal_errors(true);

        try {
            $document = simplexml_load_string($xml);

            if (false === $document) {
                throw new InvalidDmarcReportXml('Failed to parse XML: '.$this->getXmlErrors());
            }

            return $this->parseDocument($document);
        } finally {
            libxml_use_internal_errors($previousUseErrors);
        }
    }

    private function parseDocument(\SimpleXMLElement $document): ParsedDmarcReport
    {
        if (!isset($document->report_metadata)) {
            throw new InvalidDmarcReportXml('Missing <report_metadata> element.');
        }

        if (!isset($document->policy_published)) {
            throw new InvalidDmarcReportXml('Missing <policy_published> element.');
        }

        $metadata = $document->report_metadata;
        $policy = $document->policy_published;

        $reporterOrg = (string) ($metadata->org_name ?? '');
        $reporterEmail = (string) ($metadata->email ?? '');
        $reportId = (string) ($metadata->report_id ?? '');

        if ('' === $reportId) {
            throw new InvalidDmarcReportXml('Missing <report_id> in report metadata.');
        }

        $dateRangeBegin = $this->parseTimestamp($metadata->date_range->begin ?? null, 'date_range.begin');
        $dateRangeEnd = $this->parseTimestamp($metadata->date_range->end ?? null, 'date_range.end');

        $policyDomain = (string) ($policy->domain ?? '');
        if ('' === $policyDomain) {
            throw new InvalidDmarcReportXml('Missing <domain> in policy_published.');
        }

        $policyAdkim = DmarcAlignment::tryFrom((string) ($policy->adkim ?? 'r')) ?? DmarcAlignment::Relaxed;
        $policyAspf = DmarcAlignment::tryFrom((string) ($policy->aspf ?? 'r')) ?? DmarcAlignment::Relaxed;
        $policyP = DmarcPolicy::tryFrom((string) ($policy->p ?? ''));
        if (null === $policyP) {
            throw new InvalidDmarcReportXml('Invalid or missing <p> in policy_published.');
        }

        $spValue = (string) ($policy->sp ?? '');
        $policySp = '' !== $spValue ? DmarcPolicy::tryFrom($spValue) : null;

        $pctValue = (string) ($policy->pct ?? '100');
        $policyPct = '' !== $pctValue ? (int) $pctValue : 100;

        $records = [];
        foreach ($document->record as $record) {
            $records[] = $this->parseRecord($record);
        }

        return new ParsedDmarcReport(
            reporterOrg: $reporterOrg,
            reporterEmail: $reporterEmail,
            reportId: $reportId,
            dateRangeBegin: $dateRangeBegin,
            dateRangeEnd: $dateRangeEnd,
            policyDomain: $policyDomain,
            policyAdkim: $policyAdkim,
            policyAspf: $policyAspf,
            policyP: $policyP,
            policySp: $policySp,
            policyPct: $policyPct,
            records: $records,
        );
    }

    private function parseRecord(\SimpleXMLElement $record): ParsedDmarcRecord
    {
        $row = $record->row;
        $sourceIp = (string) ($row->source_ip ?? '');
        $count = (int) (string) ($row->count ?? '0');

        $policyEvaluated = $row->policy_evaluated;
        $disposition = Disposition::tryFrom((string) ($policyEvaluated->disposition ?? 'none')) ?? Disposition::None;
        $dkimResult = AuthResult::tryFrom((string) ($policyEvaluated->dkim ?? 'none')) ?? AuthResult::None;
        $spfResult = AuthResult::tryFrom((string) ($policyEvaluated->spf ?? 'none')) ?? AuthResult::None;

        $headerFrom = (string) ($record->identifiers->header_from ?? '');

        $policyOverrideReasons = $this->parsePolicyOverrideReasons($policyEvaluated);

        $dkimDomain = null;
        $dkimSelector = null;
        if (isset($record->auth_results->dkim)) {
            $dkimDomain = (string) ($record->auth_results->dkim->domain ?? '') ?: null;
            $dkimSelector = (string) ($record->auth_results->dkim->selector ?? '') ?: null;
        }

        $spfDomain = null;
        if (isset($record->auth_results->spf)) {
            $spfDomain = (string) ($record->auth_results->spf->domain ?? '') ?: null;
        }

        return new ParsedDmarcRecord(
            sourceIp: $sourceIp,
            count: $count,
            disposition: $disposition,
            dkimResult: $dkimResult,
            spfResult: $spfResult,
            headerFrom: $headerFrom,
            dkimDomain: $dkimDomain,
            dkimSelector: $dkimSelector,
            spfDomain: $spfDomain,
            policyOverrideReasons: $policyOverrideReasons,
        );
    }

    /**
     * Reads every `<reason>` the receiver attached to `<policy_evaluated>`
     * (RFC 7489 §6.7).
     *
     * A record may legitimately carry more than one — a message can be both
     * sampled out and forwarded — so this reads the repeated element rather
     * than the first one. Unknown `<type>` tokens fold into `Other`; nothing in
     * here can reject a report, because a receiver's optional annotation is
     * never worth losing an otherwise-valid report over.
     *
     * @return list<PolicyOverrideReason>
     */
    private function parsePolicyOverrideReasons(?\SimpleXMLElement $policyEvaluated): array
    {
        $reasons = [];

        foreach ($policyEvaluated->reason ?? [] as $reason) {
            $reasons[] = new PolicyOverrideReason(
                type: PolicyOverrideReasonType::fromReportValue((string) ($reason->type ?? '')),
                comment: (string) ($reason->comment ?? ''),
            );
        }

        return $reasons;
    }

    private function parseTimestamp(?\SimpleXMLElement $element, string $fieldName): \DateTimeImmutable
    {
        if (null === $element) {
            throw new InvalidDmarcReportXml(sprintf('Missing <%s> in report metadata.', $fieldName));
        }

        $timestamp = (int) (string) $element;
        if ($timestamp <= 0) {
            throw new InvalidDmarcReportXml(sprintf('Invalid timestamp in <%s>.', $fieldName));
        }

        return (new \DateTimeImmutable())->setTimestamp($timestamp);
    }

    private function getXmlErrors(): string
    {
        $errors = libxml_get_errors();
        libxml_clear_errors();

        $messages = array_map(
            static fn (\LibXMLError $error): string => trim($error->message),
            $errors,
        );

        return implode('; ', $messages) ?: 'Unknown XML error';
    }
}
