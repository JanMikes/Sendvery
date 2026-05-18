<?php

declare(strict_types=1);

namespace App\Services\Dns;

use App\Value\Dns\DkimCheckResult;
use App\Value\Dns\DkimLookupOutcome;
use App\Value\Dns\DnsIssue;
use App\Value\Dns\IssueSeverity;
use Spatie\Dns\Dns;
use Spatie\Dns\Records\CNAME;

final readonly class DkimChecker
{
    public function __construct(
        private Dns $dns,
        private EmailProviderDetector $providerDetector,
        private DkimSelectorRegistry $selectorRegistry,
    ) {
    }

    public function check(string $domain, ?string $selector = null): DkimCheckResult
    {
        if (null !== $selector) {
            $result = $this->checkSelector($domain, $selector);

            return $this->withMatchedProviders($result);
        }

        $providers = $this->providerDetector->detect($domain);
        $selectors = $this->selectorRegistry->selectorsFor($providers);

        foreach ($selectors as $candidate) {
            $result = $this->checkSelector($domain, $candidate);

            if (DkimLookupOutcome::KeyFound === $result->outcome || DkimLookupOutcome::KeyRevoked === $result->outcome) {
                return $this->withMatchedProviders($this->withDetectedProviders($result, $providers));
            }

            // Stop early if we found a CNAME at this selector — strong signal it's the right one
            if (null !== $result->cnameTarget) {
                return $this->withMatchedProviders($this->withDetectedProviders($result, $providers));
            }
        }

        return $this->withDetectedProviders($this->buildNotFoundFallback('default', $providers), $providers);
    }

    private function checkSelector(string $domain, string $selector): DkimCheckResult
    {
        $dkimDomain = "{$selector}._domainkey.{$domain}";

        $cnameTarget = $this->lookupCnameTarget($dkimDomain);
        $txtRecords = $this->lookupTxtRecords($dkimDomain);

        $rawRecord = null;
        foreach ($txtRecords as $record) {
            if (str_contains($record, 'p=')) {
                $rawRecord = $record;

                break;
            }
        }

        if (null === $rawRecord) {
            return $this->buildNoKeyResult($selector, $dkimDomain, $cnameTarget, $txtRecords);
        }

        return $this->buildKeyResult($selector, $rawRecord, $cnameTarget);
    }

    private function lookupCnameTarget(string $name): ?string
    {
        try {
            $records = $this->dns->getRecords($name, 'CNAME');
        } catch (\Throwable) {
            return null;
        }

        foreach ($records as $record) {
            if ($record instanceof CNAME) {
                $target = rtrim($record->target(), '.');
                if ('' !== $target) {
                    return $target;
                }
            }
        }

        return null;
    }

    /** @return list<string> */
    private function lookupTxtRecords(string $name): array
    {
        try {
            $records = $this->dns->getRecords($name, 'TXT');
        } catch (\Throwable) {
            return [];
        }

        $values = [];
        foreach ($records as $record) {
            $value = $this->extractTxtValue((string) $record);
            if ('' !== $value) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /** @param list<string> $txtRecords */
    private function buildNoKeyResult(string $selector, string $dkimDomain, ?string $cnameTarget, array $txtRecords): DkimCheckResult
    {
        if (null !== $cnameTarget) {
            if ([] === $txtRecords) {
                $message = "CNAME at {$dkimDomain} points to {$cnameTarget}, but no DKIM key is published at that target.";
                $help = 'Your provider has not published the key yet, or the CNAME points to the wrong selector. Verify the exact selector with your email provider.';
            } else {
                $message = "CNAME at {$dkimDomain} resolves to {$cnameTarget}, but the records there are not a DKIM key.";
                $help = "Your CNAME target ({$cnameTarget}) returns TXT records, but none contains a DKIM 'p=' tag. The CNAME likely points to the wrong location — check with your email provider for the correct target.";
            }
            $outcome = DkimLookupOutcome::CnameTargetMissingKey;
        } elseif ([] === $txtRecords) {
            $message = "No DKIM record found at {$dkimDomain}.";
            $help = '';
            $outcome = DkimLookupOutcome::NoRecord;
        } else {
            $message = "Records exist at {$dkimDomain} but none contain a DKIM key.";
            $help = "The TXT records at this name don't include a 'p=' tag. Either the selector is wrong, or your DKIM setup is misconfigured.";
            $outcome = DkimLookupOutcome::RecordsButNoDkim;
        }

        $issues = [new DnsIssue(IssueSeverity::Warning, $message, $help)];

        return new DkimCheckResult(
            rawRecord: null,
            keyExists: false,
            keyType: null,
            keyBits: null,
            selector: $selector,
            issues: $issues,
            recommendations: [],
            outcome: $outcome,
            cnameTarget: $cnameTarget,
        );
    }

    private function buildKeyResult(string $selector, string $rawRecord, ?string $cnameTarget): DkimCheckResult
    {
        $issues = [];
        $recommendations = [];

        $tags = $this->parseDkimTags($rawRecord);

        $keyType = $tags['k'] ?? 'rsa';

        $publicKeyData = $tags['p'] ?? '';
        if ('' === $publicKeyData) {
            $issues[] = new DnsIssue(IssueSeverity::Critical, 'DKIM key has been revoked (empty p= tag).', 'This DKIM selector has been explicitly revoked. Configure a new DKIM key.');
            $recommendations[] = 'Set up a new DKIM key with your email provider.';

            return new DkimCheckResult(
                rawRecord: $rawRecord,
                keyExists: true,
                keyType: $keyType,
                keyBits: null,
                selector: $selector,
                issues: $issues,
                recommendations: $recommendations,
                outcome: DkimLookupOutcome::KeyRevoked,
                cnameTarget: $cnameTarget,
            );
        }

        $keyBits = $this->estimateKeyBits($publicKeyData, $keyType);

        if ('rsa' === $keyType && null !== $keyBits && $keyBits < 2048) {
            $issues[] = new DnsIssue(
                IssueSeverity::Warning,
                "DKIM key is {$keyBits}-bit RSA. 2048-bit or stronger is recommended.",
                'Rotate to a 2048-bit RSA key or Ed25519.',
            );
            $recommendations[] = 'Upgrade your DKIM key to at least 2048-bit RSA for stronger security.';
        }

        return new DkimCheckResult(
            rawRecord: $rawRecord,
            keyExists: true,
            keyType: $keyType,
            keyBits: $keyBits,
            selector: $selector,
            issues: $issues,
            recommendations: $recommendations,
            outcome: DkimLookupOutcome::KeyFound,
            cnameTarget: $cnameTarget,
        );
    }

    /** @param list<string> $providers */
    private function buildNotFoundFallback(string $selector, array $providers): DkimCheckResult
    {
        if ([] === $providers) {
            $message = 'No DKIM key found for common selectors. You may need to specify the selector used by your email provider.';
            $help = 'Check with your email provider for the correct DKIM selector. Common selectors include: google, selector1, selector2, k1, default.';
        } else {
            $providerList = implode(', ', $providers);
            $message = "No DKIM key found. Detected provider(s): {$providerList}, but none of the known selectors for them returned a key.";
            $help = "Check your {$providerList} account dashboard for the exact DKIM selector to use, then re-run the check with that selector specified.";
        }

        return new DkimCheckResult(
            rawRecord: null,
            keyExists: false,
            keyType: null,
            keyBits: null,
            selector: $selector,
            issues: [new DnsIssue(IssueSeverity::Warning, $message, $help)],
            recommendations: [$help],
            outcome: DkimLookupOutcome::NoRecord,
        );
    }

    /** @param list<string> $providers */
    private function withDetectedProviders(DkimCheckResult $result, array $providers): DkimCheckResult
    {
        return new DkimCheckResult(
            rawRecord: $result->rawRecord,
            keyExists: $result->keyExists,
            keyType: $result->keyType,
            keyBits: $result->keyBits,
            selector: $result->selector,
            issues: $result->issues,
            recommendations: $result->recommendations,
            outcome: $result->outcome,
            cnameTarget: $result->cnameTarget,
            detectedProviders: $providers,
            matchedProviders: $result->matchedProviders,
        );
    }

    private function withMatchedProviders(DkimCheckResult $result): DkimCheckResult
    {
        $matched = $this->selectorRegistry->providersForSelector($result->selector);

        if ([] === $matched) {
            return $result;
        }

        return new DkimCheckResult(
            rawRecord: $result->rawRecord,
            keyExists: $result->keyExists,
            keyType: $result->keyType,
            keyBits: $result->keyBits,
            selector: $result->selector,
            issues: $result->issues,
            recommendations: $result->recommendations,
            outcome: $result->outcome,
            cnameTarget: $result->cnameTarget,
            detectedProviders: $result->detectedProviders,
            matchedProviders: $matched,
        );
    }

    /** @return array<string, string> */
    private function parseDkimTags(string $record): array
    {
        $tags = [];
        $parts = explode(';', $record);

        foreach ($parts as $part) {
            $part = trim($part);
            if ('' === $part) {
                continue;
            }
            $eqPos = strpos($part, '=');
            if (false === $eqPos) {
                continue;
            }
            $key = trim(substr($part, 0, $eqPos));
            $value = trim(substr($part, $eqPos + 1));
            $tags[$key] = $value;
        }

        return $tags;
    }

    private function estimateKeyBits(string $publicKeyBase64, string $keyType): ?int
    {
        $cleanKey = preg_replace('/\s+/', '', $publicKeyBase64);
        if (null === $cleanKey || '' === $cleanKey) {
            return null;
        }

        $decoded = base64_decode($cleanKey, true);
        if (false === $decoded) {
            return null;
        }

        if ('ed25519' === $keyType) {
            return 256;
        }

        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split($cleanKey, 64)."-----END PUBLIC KEY-----\n";
        $key = @openssl_pkey_get_public($pem);
        if (false === $key) {
            // Estimate from decoded length for RSA: key bytes ≈ modulus + overhead
            $len = strlen($decoded);

            return match (true) {
                $len <= 100 => 512,
                $len <= 200 => 1024,
                $len <= 300 => 2048,
                $len <= 600 => 4096,
                default => null,
            };
        }

        $details = openssl_pkey_get_details($key);

        return $details['bits'] ?? null;
    }

    private function extractTxtValue(string $record): string
    {
        // spatie/dns returns records like: "selector._domainkey.example.com.    3600    IN    TXT    "v=DKIM1; k=rsa; p=..."
        if (preg_match('/TXT\s+"?(.+?)"?\s*$/', $record, $matches)) {
            return trim($matches[1], '"');
        }

        // Strip any concatenated quoted strings
        if (preg_match_all('/"([^"]*)"/', $record, $matches)) {
            return implode('', $matches[1]);
        }

        return $record;
    }
}
