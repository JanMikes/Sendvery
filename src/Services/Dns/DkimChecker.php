<?php

declare(strict_types=1);

namespace App\Services\Dns;

use App\Value\Dns\DkimCheckResult;
use App\Value\Dns\DnsIssue;
use App\Value\Dns\IssueSeverity;
use Spatie\Dns\Dns;

final readonly class DkimChecker
{
    private const array COMMON_SELECTORS = ['default', 'google', 'selector1', 'selector2', 'k1', 's1', 'dkim', 'mail', 'smtp'];

    public function __construct(
        private Dns $dns,
    ) {
    }

    public function check(string $domain, ?string $selector = null): DkimCheckResult
    {
        if (null !== $selector) {
            return $this->checkSelector($domain, $selector);
        }

        foreach (self::COMMON_SELECTORS as $commonSelector) {
            $result = $this->checkSelector($domain, $commonSelector);

            if ($result->keyExists) {
                return $result;
            }
        }

        return new DkimCheckResult(
            rawRecord: null,
            keyExists: false,
            keyType: null,
            keyBits: null,
            selector: 'default',
            issues: [new DnsIssue(IssueSeverity::Warning, 'No DKIM key found for common selectors. You may need to specify the selector used by your email provider.')],
            recommendations: ['Check with your email provider for the correct DKIM selector. Common selectors: google, selector1, selector2, k1, default.'],
        );
    }

    private function checkSelector(string $domain, string $selector): DkimCheckResult
    {
        $dkimDomain = "{$selector}._domainkey.{$domain}";

        try {
            $records = $this->dns->getRecords($dkimDomain, 'TXT');
        } catch (\Throwable) {
            return new DkimCheckResult(
                rawRecord: null,
                keyExists: false,
                keyType: null,
                keyBits: null,
                selector: $selector,
                issues: [new DnsIssue(IssueSeverity::Info, "No DKIM record found at {$dkimDomain}")],
                recommendations: [],
            );
        }

        $rawRecord = null;
        foreach ($records as $record) {
            $txt = (string) $record;
            if (str_contains($txt, 'p=')) {
                $rawRecord = $this->extractTxtValue($txt);

                break;
            }
        }

        if (null === $rawRecord) {
            return new DkimCheckResult(
                rawRecord: null,
                keyExists: false,
                keyType: null,
                keyBits: null,
                selector: $selector,
                issues: [new DnsIssue(IssueSeverity::Info, "No DKIM record found at {$dkimDomain}")],
                recommendations: [],
            );
        }

        $issues = [];
        $recommendations = [];
        $keyType = null;
        $keyBits = null;

        $tags = $this->parseDkimTags($rawRecord);

        $keyType = $tags['k'] ?? 'rsa';

        $publicKeyData = $tags['p'] ?? '';
        if ('' === $publicKeyData) {
            $issues[] = new DnsIssue(IssueSeverity::Critical, 'DKIM key has been revoked (empty p= tag).', 'This DKIM selector has been explicitly revoked. Configure a new DKIM key.');
            $recommendations[] = 'Set up a new DKIM key with your email provider.';
        } else {
            $keyBits = $this->estimateKeyBits($publicKeyData, $keyType);

            if ('rsa' === $keyType && null !== $keyBits && $keyBits < 2048) {
                $issues[] = new DnsIssue(
                    IssueSeverity::Warning,
                    "DKIM key is {$keyBits}-bit RSA. 2048-bit or stronger is recommended.",
                    'Rotate to a 2048-bit RSA key or Ed25519.',
                );
                $recommendations[] = 'Upgrade your DKIM key to at least 2048-bit RSA for stronger security.';
            }
        }

        return new DkimCheckResult(
            rawRecord: $rawRecord,
            keyExists: true,
            keyType: $keyType,
            keyBits: $keyBits,
            selector: $selector,
            issues: $issues,
            recommendations: $recommendations,
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
