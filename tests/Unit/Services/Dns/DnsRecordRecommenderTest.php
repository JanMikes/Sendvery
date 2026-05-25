<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Dns;

use App\Entity\DnsCheckResult;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Services\Dns\DnsRecordRecommender;
use App\Services\ReportAddressProvider;
use App\Value\Dns\DnsRecordCategory;
use App\Value\DnsCheckType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Pure-computation coverage for {@see DnsRecordRecommender} (TASK-095). One
 * branch per category × eligibility state — locks the recommendation copy +
 * shape so future refactors of the DnsCheckResult details map can't silently
 * drop a recommendation off the dashboard.
 */
final class DnsRecordRecommenderTest extends TestCase
{
    #[Test]
    public function spfMissingRecommendsStrictBaseline(): void
    {
        $recommender = $this->makeRecommender();

        $recs = $recommender->recommendForDomain('example.com', [
            DnsCheckType::Spf->value => null,
        ]);

        self::assertArrayHasKey(DnsRecordCategory::Spf->value, $recs);
        $rec = $recs[DnsRecordCategory::Spf->value];
        self::assertSame('missing', $rec->severity);
        self::assertSame('TXT', $rec->recordType);
        self::assertSame('example.com', $rec->recordHost);
        self::assertSame('v=spf1 -all', $rec->recommendedValue);
        self::assertStringContainsString('no SPF record', $rec->whyText);
    }

    #[Test]
    public function spfMissingTreatsEmptyRawAsAbsent(): void
    {
        // Defensive: a DnsCheckResult row can land with `rawRecord` = empty
        // string in pathological resolver-failure cases. Treat it as missing.
        $recommender = $this->makeRecommender();
        $spf = $this->makeCheck(DnsCheckType::Spf, rawRecord: '   ', isValid: false);

        $recs = $recommender->recommendForDomain('example.com', [
            DnsCheckType::Spf->value => $spf,
        ]);

        self::assertArrayHasKey(DnsRecordCategory::Spf->value, $recs);
        self::assertSame('missing', $recs[DnsRecordCategory::Spf->value]->severity);
    }

    #[Test]
    public function spfOverLookupLimitRecommendsTrimWithoutValue(): void
    {
        $recommender = $this->makeRecommender();
        $spf = $this->makeCheck(
            DnsCheckType::Spf,
            rawRecord: 'v=spf1 include:_spf.google.com include:spf.mailgun.org -all',
            isValid: false,
            details: ['lookup_count' => 12, 'includes' => ['_spf.google.com']],
        );

        $recs = $recommender->recommendForDomain('example.com', [
            DnsCheckType::Spf->value => $spf,
        ]);

        $rec = $recs[DnsRecordCategory::Spf->value];
        self::assertSame('suboptimal', $rec->severity);
        self::assertNull($rec->recommendedValue, 'Trim-this guidance must not include a copyable record.');
        self::assertStringContainsString('12 lookups', $rec->whyText);
        self::assertStringContainsString('google.com', $rec->whyText);
    }

    #[Test]
    public function spfOverLookupLimitFallsBackWhenProviderUnknown(): void
    {
        $recommender = $this->makeRecommender();
        $spf = $this->makeCheck(
            DnsCheckType::Spf,
            rawRecord: 'v=spf1 include:exotic.example.net -all',
            isValid: false,
            details: ['lookup_count' => 11, 'includes' => ['exotic.example.net']],
        );

        $recs = $recommender->recommendForDomain('example.com', [
            DnsCheckType::Spf->value => $spf,
        ]);

        $rec = $recs[DnsRecordCategory::Spf->value];
        self::assertSame('suboptimal', $rec->severity);
        self::assertStringContainsString('a provider you no longer use', $rec->whyText);
    }

    #[Test]
    public function spfAtTheLimitIsNotRecommended(): void
    {
        // Boundary check: lookups == 10 is allowed by RFC; only > 10 trips
        // the suboptimal branch.
        $recommender = $this->makeRecommender();
        $spf = $this->makeCheck(
            DnsCheckType::Spf,
            rawRecord: 'v=spf1 -all',
            isValid: true,
            details: ['lookup_count' => 10],
        );

        $recs = $recommender->recommendForDomain('example.com', [
            DnsCheckType::Spf->value => $spf,
        ]);

        self::assertArrayNotHasKey(DnsRecordCategory::Spf->value, $recs);
    }

    #[Test]
    public function dkimMissingRecommendsHowToWithoutValue(): void
    {
        $recommender = $this->makeRecommender();

        $recs = $recommender->recommendForDomain('example.com', [
            DnsCheckType::Dkim->value => null,
        ]);

        self::assertArrayHasKey(DnsRecordCategory::Dkim->value, $recs);
        $rec = $recs[DnsRecordCategory::Dkim->value];
        self::assertSame('missing', $rec->severity);
        self::assertNull($rec->recommendedValue);
        self::assertStringContainsString('<selector>._domainkey.example.com', $rec->recordHost);
        self::assertStringContainsString('google', $rec->whyText);
    }

    #[Test]
    public function dkimValidIsNotRecommended(): void
    {
        $recommender = $this->makeRecommender();
        $dkim = $this->makeCheck(DnsCheckType::Dkim, rawRecord: 'v=DKIM1; k=rsa; p=...', isValid: true);

        $recs = $recommender->recommendForDomain('example.com', [
            DnsCheckType::Dkim->value => $dkim,
        ]);

        self::assertArrayNotHasKey(DnsRecordCategory::Dkim->value, $recs);
    }

    #[Test]
    public function dmarcMissingRecommendsPublishRecord(): void
    {
        $recommender = $this->makeRecommender();

        $recs = $recommender->recommendForDomain('example.com', [
            DnsCheckType::Dmarc->value => null,
        ]);

        self::assertArrayHasKey(DnsRecordCategory::Dmarc->value, $recs);
        $rec = $recs[DnsRecordCategory::Dmarc->value];
        self::assertSame('missing', $rec->severity);
        self::assertSame('_dmarc.example.com', $rec->recordHost);
        self::assertNotNull($rec->recommendedValue);
        self::assertStringContainsString('reports@sendvery.test', $rec->recommendedValue);
    }

    #[Test]
    public function dmarcAlreadyConfiguredEmitsNothing(): void
    {
        // The recommender returns no DMARC card when the rua= already
        // includes the report address — that's the "all set" state.
        $recommender = $this->makeRecommender();
        $dmarc = $this->makeCheck(
            DnsCheckType::Dmarc,
            rawRecord: 'v=DMARC1; p=none; rua=mailto:reports@sendvery.test; fo=1; adkim=r; aspf=r',
            isValid: true,
        );

        $recs = $recommender->recommendForDomain('example.com', [
            DnsCheckType::Dmarc->value => $dmarc,
        ]);

        self::assertArrayNotHasKey(DnsRecordCategory::Dmarc->value, $recs);
    }

    #[Test]
    public function dmarcAddingSendveryToExistingRecordIsBrokenSeverity(): void
    {
        // Edge case: domain already has a DMARC record pointing elsewhere.
        // We recommend the rua= add; severity is `broken` (the record exists
        // but doesn't include us), not `missing`.
        $recommender = $this->makeRecommender();
        $dmarc = $this->makeCheck(
            DnsCheckType::Dmarc,
            rawRecord: 'v=DMARC1; p=none; rua=mailto:reports@external.example',
            isValid: true,
        );

        $recs = $recommender->recommendForDomain('example.com', [
            DnsCheckType::Dmarc->value => $dmarc,
        ]);

        $rec = $recs[DnsRecordCategory::Dmarc->value];
        self::assertSame('broken', $rec->severity);
        self::assertStringContainsString('reports@sendvery.test', (string) $rec->recommendedValue);
        self::assertStringContainsString('reports@external.example', (string) $rec->recommendedValue);
    }

    #[Test]
    public function mxNeverRecommended(): void
    {
        // Out of scope of email-receiving recommendations — we don't run
        // the user's inbound mail server.
        $recommender = $this->makeRecommender();
        $mx = $this->makeCheck(DnsCheckType::Mx, rawRecord: null, isValid: false);

        $recs = $recommender->recommendForDomain('example.com', [
            DnsCheckType::Mx->value => $mx,
        ]);

        self::assertArrayNotHasKey(DnsRecordCategory::Mx->value, $recs);
    }

    #[Test]
    public function emptyDetailsArrayDoesNotCrashSpfTrimBranch(): void
    {
        // Defensive: the SnapshotDomainHealth shape always serializes a
        // details array, but we shouldn't trust the shape blindly. A check
        // with `lookup_count` missing must fall back to "no recommendation".
        $recommender = $this->makeRecommender();
        $spf = $this->makeCheck(
            DnsCheckType::Spf,
            rawRecord: 'v=spf1 -all',
            isValid: true,
            details: [],
        );

        $recs = $recommender->recommendForDomain('example.com', [
            DnsCheckType::Spf->value => $spf,
        ]);

        self::assertArrayNotHasKey(DnsRecordCategory::Spf->value, $recs);
    }

    private function makeRecommender(): DnsRecordRecommender
    {
        return new DnsRecordRecommender(new ReportAddressProvider('reports@sendvery.test'));
    }

    /** @param array<string, mixed> $details */
    private function makeCheck(
        DnsCheckType $type,
        ?string $rawRecord,
        bool $isValid,
        array $details = [],
    ): DnsCheckResult {
        $team = new Team(
            id: Uuid::uuid7(),
            name: 'T',
            slug: 't-'.bin2hex(random_bytes(3)),
            createdAt: new \DateTimeImmutable(),
        );
        $team->popEvents();

        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'example.com',
            createdAt: new \DateTimeImmutable(),
        );
        $domain->popEvents();

        $check = new DnsCheckResult(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            type: $type,
            checkedAt: new \DateTimeImmutable(),
            rawRecord: $rawRecord,
            isValid: $isValid,
            issues: [],
            details: $details,
            previousRawRecord: null,
            hasChanged: false,
        );
        $check->popEvents();

        return $check;
    }
}
