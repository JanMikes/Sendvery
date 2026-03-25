<?php

declare(strict_types=1);

namespace App\Services\Dns;

use App\Value\Dns\DnsIssue;
use App\Value\Dns\IssueSeverity;
use App\Value\Dns\SpfCheckResult;
use SPFLib\Decoder;
use SPFLib\Exception;
use SPFLib\SemanticValidator;
use SPFLib\Term\Mechanism;
use SPFLib\Term\Mechanism\IncludeMechanism;
use SPFLib\Term\Modifier\RedirectModifier;

final readonly class SpfChecker
{
    public function __construct(
        private Decoder $spfDecoder,
        private SemanticValidator $semanticValidator,
    ) {
    }

    public function check(string $domain): SpfCheckResult
    {
        try {
            $rawRecord = $this->spfDecoder->getTXTRecordFromDomain($domain);
        } catch (Exception $e) {
            return new SpfCheckResult(
                rawRecord: null,
                isValid: false,
                mechanismCount: 0,
                lookupCount: 0,
                includes: [],
                issues: [new DnsIssue(IssueSeverity::Critical, 'Failed to query SPF record: '.$e->getMessage())],
                recommendations: ['Ensure your domain has a valid SPF TXT record.'],
            );
        }

        if ('' === $rawRecord) {
            return new SpfCheckResult(
                rawRecord: null,
                isValid: false,
                mechanismCount: 0,
                lookupCount: 0,
                includes: [],
                issues: [new DnsIssue(IssueSeverity::Critical, 'No SPF record found for this domain.')],
                recommendations: ['Add an SPF TXT record to your domain DNS. Example: v=spf1 include:_spf.google.com ~all'],
            );
        }

        try {
            $record = $this->spfDecoder->getRecordFromTXT($rawRecord);
        } catch (Exception $e) {
            return new SpfCheckResult(
                rawRecord: $rawRecord,
                isValid: false,
                mechanismCount: 0,
                lookupCount: 0,
                includes: [],
                issues: [new DnsIssue(IssueSeverity::Critical, 'SPF record has syntax errors: '.$e->getMessage())],
                recommendations: ['Fix the SPF record syntax. Use an SPF record generator tool.'],
            );
        }

        if (null === $record) {
            return new SpfCheckResult(
                rawRecord: $rawRecord,
                isValid: false,
                mechanismCount: 0,
                lookupCount: 0,
                includes: [],
                issues: [new DnsIssue(IssueSeverity::Warning, 'SPF record could not be parsed.')],
                recommendations: ['Verify your SPF record syntax.'],
            );
        }

        $issues = [];
        $recommendations = [];
        $includes = [];
        $lookupCount = 0;
        $mechanismCount = 0;
        $hasAllPass = false;

        foreach ($record->getTerms() as $term) {
            if ($term instanceof Mechanism) {
                ++$mechanismCount;
            }

            if ($term instanceof IncludeMechanism) {
                $includes[] = (string) $term->getDomainSpec();
                ++$lookupCount;
            } elseif ($term instanceof Mechanism\AMechanism || $term instanceof Mechanism\MxMechanism || $term instanceof Mechanism\PtrMechanism || $term instanceof Mechanism\ExistsMechanism) {
                ++$lookupCount;
            } elseif ($term instanceof RedirectModifier) {
                ++$lookupCount;
            }

            if ($term instanceof Mechanism\AllMechanism && Mechanism::QUALIFIER_PASS === $term->getQualifier()) {
                $hasAllPass = true;
            }
        }

        if ($hasAllPass) {
            $issues[] = new DnsIssue(
                IssueSeverity::Critical,
                'SPF record uses +all which allows any server to send email as your domain.',
                'Change +all to ~all (softfail) or -all (hardfail).',
            );
            $recommendations[] = 'Replace +all with ~all or -all to prevent unauthorized senders.';
        }

        if ($lookupCount > 10) {
            $issues[] = new DnsIssue(
                IssueSeverity::Critical,
                "SPF record requires {$lookupCount} DNS lookups, exceeding the 10-lookup limit (RFC 7208).",
                'Reduce includes or use SPF flattening to stay under 10 lookups.',
            );
            $recommendations[] = 'Flatten your SPF record or remove unused include statements to stay under 10 DNS lookups.';
        } elseif ($lookupCount >= 8) {
            $issues[] = new DnsIssue(
                IssueSeverity::Warning,
                "SPF record uses {$lookupCount} of 10 allowed DNS lookups. Close to the limit.",
                'Consider flattening some includes to leave room for future services.',
            );
            $recommendations[] = 'You are close to the 10-lookup limit. Plan ahead before adding new email services.';
        }

        $semanticIssues = $this->semanticValidator->validate($record);
        foreach ($semanticIssues as $issue) {
            $severity = match ($issue->getLevel()) {
                \SPFLib\Semantic\AbstractIssue::LEVEL_FATAL => IssueSeverity::Critical,
                \SPFLib\Semantic\AbstractIssue::LEVEL_WARNING => IssueSeverity::Warning,
                default => IssueSeverity::Info,
            };
            $issues[] = new DnsIssue($severity, $issue->getDescription());
        }

        $isValid = true;
        foreach ($issues as $issue) {
            if (IssueSeverity::Critical === $issue->severity) {
                $isValid = false;

                break;
            }
        }

        return new SpfCheckResult(
            rawRecord: $rawRecord,
            isValid: $isValid,
            mechanismCount: $mechanismCount,
            lookupCount: $lookupCount,
            includes: $includes,
            issues: $issues,
            recommendations: $recommendations,
        );
    }
}
