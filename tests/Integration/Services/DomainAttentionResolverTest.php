<?php

declare(strict_types=1);

namespace App\Tests\Integration\Services;

use App\Entity\DmarcRecord;
use App\Entity\DmarcReport;
use App\Entity\DnsCheckResult;
use App\Entity\DomainHealthSnapshot;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Query\GetDomainOverview;
use App\Results\DomainAttentionListResult;
use App\Results\DomainAttentionResult;
use App\Results\DomainVerificationStatusResult;
use App\Services\DomainAttentionResolver;
use App\Tests\Fixtures\Persona;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use App\Value\AuthResult;
use App\Value\Disposition;
use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;
use App\Value\DnsCheckType;
use App\Value\DomainHealthFilter;
use App\Value\DomainVerificationSeverity;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;

/**
 * Drives the `/app` attention list: which domains land on it, what each row says
 * is wrong, in which order, and where each row sends the user.
 *
 * Integration rather than unit: the resolver reads through `final readonly`
 * queries that cannot be doubled, and the whole point of the class is that its
 * answers come from the real per-domain setup resolver rather than from copy of
 * its own.
 */
final class DomainAttentionResolverTest extends WebTestCase
{
    private const string DMARC_RECORD_POINTING_AT_SENDVERY = 'v=DMARC1; p=none; rua=mailto:reports@sendvery.com;';

    #[Test]
    public function aFullyHealthyDomainIsNotListedAtAll(): void
    {
        $persona = $this->bootPersonaWithoutDomain();
        $this->healthyDomain($persona->team);
        $this->flush();

        $result = $this->resolve($persona);

        self::assertSame(0, $result->totalCount);
        self::assertSame([], $result->domains);
    }

    #[Test]
    public function aMissingRecordIsReportedInTheWordsThePerDomainPageUses(): void
    {
        $persona = $this->bootPersonaWithoutDomain();
        $domain = $this->healthyDomain($persona->team);
        // Everything in place except SPF, which resolved to nothing.
        $domain->spfVerifiedAt = null;
        $this->check($domain, DnsCheckType::Spf, rawRecord: null, isValid: false);
        $this->flush();

        $row = $this->onlyRow($this->resolve($persona));

        self::assertSame(DomainHealthFilter::Attention, $row->severity);
        self::assertSame('Action needed — SPF', $row->headline);
        self::assertSame('SPF', $row->reasons[0]->label);
        self::assertSame('SPF record not detected', $row->reasons[0]->detail);
        self::assertSame('error', $row->reasons[0]->tone, 'Nothing published at all is the error tone.');
    }

    #[Test]
    public function aPublishedButBrokenRecordIsTheWarningToneNotTheErrorTone(): void
    {
        // "Fix the record you have" is a different job from "publish a record",
        // and the row has to let the user tell them apart at a glance.
        $persona = $this->bootPersonaWithoutDomain();
        $domain = $this->healthyDomain($persona->team);
        $domain->spfVerifiedAt = null;
        $this->check($domain, DnsCheckType::Spf, rawRecord: 'v=spf1 include:broken', isValid: false);
        $this->flush();

        $row = $this->onlyRow($this->resolve($persona));

        self::assertSame('SPF record present but failing checks', $row->reasons[0]->detail);
        self::assertSame('warning', $row->reasons[0]->tone);
    }

    #[Test]
    public function theRowLinksStraightToTheRecordItIsComplainingAbout(): void
    {
        $persona = $this->bootPersonaWithoutDomain();
        $domain = $this->healthyDomain($persona->team);
        $domain->spfVerifiedAt = null;
        $this->check($domain, DnsCheckType::Spf, rawRecord: null, isValid: false);
        $this->flush();

        $row = $this->onlyRow($this->resolve($persona));

        self::assertSame('Fix DNS records', $row->ctaLabel);
        self::assertSame('dashboard_domain_health', $row->ctaRoute);
        self::assertSame(
            ['id' => $domain->id->toString(), '_fragment' => 'health-spf'],
            $row->ctaRouteParams,
            'The deep link must land on the SPF section, not the top of the page.',
        );
    }

    #[Test]
    public function theRecordTheCtaJumpsToIsTheFirstReasonListed(): void
    {
        // Several records missing at once: the row must lead with whichever one
        // the fix surface considers most urgent, not with whichever the protocol
        // list happens to iterate first.
        $persona = $this->bootPersonaWithoutDomain();
        $domain = $this->healthyDomain($persona->team);
        $domain->spfVerifiedAt = null;
        $this->check($domain, DnsCheckType::Spf, rawRecord: null, isValid: false);
        $this->check($domain, DnsCheckType::Dmarc, rawRecord: null, isValid: false);
        $this->flush();

        $row = $this->onlyRow($this->resolve($persona));

        self::assertSame('health-dmarc', $row->ctaRouteParams['_fragment']);
        self::assertSame(
            'DMARC',
            $row->reasons[0]->label,
            'Report delivery blocks everything else, so it leads the reason list.',
        );
    }

    #[Test]
    public function aDomainWithEveryRecordInPlaceButAFailingPassRateSaysSo(): void
    {
        // The one reason no protocol row can express: the records are all fine and
        // mail is still failing authentication.
        $persona = $this->bootPersonaWithoutDomain();
        $domain = $this->healthyDomain($persona->team);
        $this->report($domain, pass: 2, fail: 8);
        $this->flush();

        $row = $this->onlyRow($this->resolve($persona));

        self::assertSame(DomainHealthFilter::Attention, $row->severity);
        self::assertCount(1, $row->reasons);
        self::assertSame('DMARC pass rate', $row->reasons[0]->label);
        self::assertSame('Only 20.0% of messages passed DMARC in the last 30 days', $row->reasons[0]->detail);
        self::assertSame('dashboard_domain_detail', $row->ctaRoute, 'DNS is fine, so the fix surface is the domain itself.');
        self::assertSame(['id' => $domain->id->toString()], $row->ctaRouteParams);
    }

    #[Test]
    public function aCorrectlyConfiguredDomainAwaitingItsFirstReportIsNotAccusedOfFailing(): void
    {
        // Null pass rate is "nothing to grade", never "0% passed" — such a domain
        // is healthy and must not appear here at all.
        $persona = $this->bootPersonaWithoutDomain();
        $this->healthyDomain($persona->team);
        $this->flush();

        $result = $this->resolve($persona);

        self::assertSame(0, $result->totalCount);
    }

    #[Test]
    public function aDomainWhoseFirstCheckHasNotFinishedSaysSoInsteadOfNamingMissingRecords(): void
    {
        $persona = $this->bootPersonaWithoutDomain();
        $this->bareDomain($persona->team);
        $this->flush();

        $row = $this->onlyRow($this->resolve($persona));

        self::assertTrue($row->checkInProgress);
        self::assertSame('Checking your DNS now', $row->headline);
        self::assertCount(1, $row->reasons);
        self::assertStringContainsString('first check is still running', $row->reasons[0]->detail);
        self::assertSame('See progress', $row->ctaLabel);
        self::assertSame('info', $row->severityTone(), 'We have not looked yet, so the row cannot be red.');
    }

    #[Test]
    public function unverifiedDomainsOutrankDomainsThatMerelyNeedAttention(): void
    {
        $persona = $this->bootPersonaWithoutDomain();
        $attention = $this->healthyDomain($persona->team, name: 'zz-attention');
        $attention->spfVerifiedAt = null;
        $this->check($attention, DnsCheckType::Spf, rawRecord: null, isValid: false);
        $unverified = $this->healthyDomain($persona->team, name: 'aa-unverified');
        $unverified->dmarcVerifiedAt = null;
        $this->flush();

        $result = $this->resolve($persona);

        self::assertSame(2, $result->totalCount);
        self::assertSame(
            $unverified->domain,
            $result->domains[0]->domainName,
            'A domain we receive nothing for cannot be improved by tuning anything else.',
        );
        self::assertSame($attention->domain, $result->domains[1]->domainName);
    }

    #[Test]
    public function theWorstMeasuredPassRateLeadsWithinTheSameBucket(): void
    {
        $persona = $this->bootPersonaWithoutDomain();
        $mild = $this->healthyDomain($persona->team, name: 'aa-mild');
        $this->report($mild, pass: 8, fail: 2);
        $severe = $this->healthyDomain($persona->team, name: 'zz-severe');
        $this->report($severe, pass: 1, fail: 9);
        $this->flush();

        $result = $this->resolve($persona);

        self::assertSame($severe->domain, $result->domains[0]->domainName);
        self::assertSame($mild->domain, $result->domains[1]->domainName);
    }

    #[Test]
    public function theListIsCappedAndReportsHowManyDomainsItHid(): void
    {
        $persona = $this->bootPersonaWithoutDomain();
        for ($i = 0; $i < 3; ++$i) {
            $domain = $this->healthyDomain($persona->team, name: sprintf('capped-%d', $i));
            $domain->dmarcVerifiedAt = null;
        }
        $this->flush();

        $result = $this->resolve($persona, limit: 2);

        self::assertSame(3, $result->totalCount);
        self::assertCount(2, $result->domains);
        self::assertSame(1, $result->hiddenCount);
    }

    #[Test]
    public function aRegressedDmarcRecordIsReportedAsHavingGoneMissingRatherThanNeverPublished(): void
    {
        // The per-protocol row says "not detected" either way. Only the
        // verification history can tell the user this used to work.
        $persona = $this->bootPersonaWithoutDomain();
        $domain = $this->domainNeedingAttention($persona->team);
        $this->flush();

        $row = $this->onlyRow($this->resolve(
            $persona,
            verificationStatus: $this->verificationStatus($domain, dmarcVerifiedAt: new \DateTimeImmutable('-30 days'), consecutiveDmarcFailures: 2),
            verificationSeverity: DomainVerificationSeverity::Critical,
        ));

        self::assertSame('DMARC record went missing', $row->reasons[0]->label);
        self::assertStringContainsString("the last 2 checks couldn't find it", $row->reasons[0]->detail);
        self::assertSame('error', $row->reasons[0]->tone);
    }

    #[Test]
    public function aRecentlyVerifiedRecordThatOneCheckMissedIsTreatedAsPropagationNotBreakage(): void
    {
        $persona = $this->bootPersonaWithoutDomain();
        $domain = $this->domainNeedingAttention($persona->team);
        $this->flush();

        $row = $this->onlyRow($this->resolve(
            $persona,
            verificationStatus: $this->verificationStatus($domain, dmarcVerifiedAt: new \DateTimeImmutable('-2 hours'), consecutiveDmarcFailures: 1),
            verificationSeverity: DomainVerificationSeverity::Info,
        ));

        self::assertSame('Confirming DMARC record', $row->reasons[0]->label);
        self::assertSame('info', $row->reasons[0]->tone, 'Escalating propagation delays trains users to ignore us.');
    }

    #[Test]
    public function aPublishedRecordWithNoReportsAfterTwoDaysPointsAtTheRuaTag(): void
    {
        $persona = $this->bootPersonaWithoutDomain();
        $domain = $this->domainNeedingAttention($persona->team);
        $this->flush();

        $row = $this->onlyRow($this->resolve(
            $persona,
            verificationStatus: $this->verificationStatus($domain, dmarcVerifiedAt: new \DateTimeImmutable('-5 days'), consecutiveDmarcFailures: 0),
            verificationSeverity: DomainVerificationSeverity::Warning,
        ));

        self::assertSame('No DMARC reports yet', $row->reasons[0]->label);
        self::assertStringContainsString('rua=', $row->reasons[0]->detail);
    }

    #[Test]
    public function reportsAlreadyQueuedForAnUnverifiedDomainAreSurfacedOnItsRow(): void
    {
        // The strongest nudge to finish DNS there is, and it is invisible to any
        // DNS check: mail providers are already sending us this domain's reports.
        $persona = $this->bootPersonaWithoutDomain();
        $domain = $this->healthyDomain($persona->team);
        $domain->dmarcVerifiedAt = null;
        $this->flush();

        $row = $this->onlyRow($this->resolve(
            $persona,
            verificationStatus: $this->verificationStatus($domain, dmarcVerifiedAt: null, consecutiveDmarcFailures: 0),
            verificationSeverity: DomainVerificationSeverity::Critical,
            quarantineCount: 4,
        ));

        self::assertSame('Reports already waiting', $row->reasons[0]->label);
        self::assertStringContainsString('4 DMARC reports arrived', $row->reasons[0]->detail);
    }

    #[Test]
    public function aVerifiedRecordWithNothingWrongAddsNoVerificationReason(): void
    {
        $persona = $this->bootPersonaWithoutDomain();
        $domain = $this->healthyDomain($persona->team);
        $domain->spfVerifiedAt = null;
        $this->check($domain, DnsCheckType::Spf, rawRecord: null, isValid: false);
        $this->flush();

        $row = $this->onlyRow($this->resolve(
            $persona,
            verificationStatus: $this->verificationStatus($domain, dmarcVerifiedAt: new \DateTimeImmutable('-9 days'), consecutiveDmarcFailures: 0),
            verificationSeverity: DomainVerificationSeverity::Ok,
        ));

        self::assertSame('SPF', $row->reasons[0]->label);
    }

    #[Test]
    public function aRowNeverRendersWithASeverityAndNothingToExplainIt(): void
    {
        // Verified check rows for all four protocols, but no health snapshot, so
        // the classifier will not call the domain Healthy while the per-protocol
        // rows all read Configured. The row must still say something true.
        $persona = $this->bootPersonaWithoutDomain();
        $domain = $this->bareDomain($persona->team);
        $domain->dmarcVerifiedAt = new \DateTimeImmutable('-1 day');
        foreach ([DnsCheckType::Spf, DnsCheckType::Dkim, DnsCheckType::Mx] as $type) {
            $this->check($domain, $type, rawRecord: 'ok', isValid: true);
        }
        $this->check($domain, DnsCheckType::Dmarc, rawRecord: self::DMARC_RECORD_POINTING_AT_SENDVERY, isValid: true);
        $this->flush();

        $row = $this->onlyRow($this->resolve($persona));

        self::assertSame('Waiting on a full DNS check', $row->headline);
        self::assertCount(1, $row->reasons);
        self::assertSame('DNS verification', $row->reasons[0]->label);
    }

    private function resolve(
        Persona $persona,
        int $limit = DomainAttentionResolver::DEFAULT_LIMIT,
        ?DomainVerificationStatusResult $verificationStatus = null,
        ?DomainVerificationSeverity $verificationSeverity = null,
        int $quarantineCount = 0,
    ): DomainAttentionListResult {
        $teamIds = [$persona->team->id->toString()];
        $domains = $this->getService(GetDomainOverview::class)->forTeams($teamIds);

        return $this->getService(DomainAttentionResolver::class)->resolve(
            domains: $domains,
            teamIds: $teamIds,
            limit: $limit,
            verificationStatus: $verificationStatus,
            verificationSeverity: $verificationSeverity,
            quarantineCount: $quarantineCount,
        );
    }

    private function onlyRow(DomainAttentionListResult $result): DomainAttentionResult
    {
        self::assertCount(1, $result->domains, 'This case is written around exactly one listed domain.');

        return $result->domains[0];
    }

    private function verificationStatus(
        MonitoredDomain $domain,
        ?\DateTimeImmutable $dmarcVerifiedAt,
        int $consecutiveDmarcFailures,
    ): DomainVerificationStatusResult {
        return new DomainVerificationStatusResult(
            domainId: $domain->id->toString(),
            domainName: $domain->domain,
            spfVerifiedAt: null,
            dkimVerifiedAt: null,
            dmarcVerifiedAt: $dmarcVerifiedAt,
            firstReportAt: null,
            consecutiveDmarcFailures: $consecutiveDmarcFailures,
        );
    }

    private function bootPersonaWithoutDomain(): Persona
    {
        self::createClient();

        return TestFixtures::fromContainer(self::getContainer())->persona()->withoutDomain()->build();
    }

    /**
     * A domain with nothing recorded about it yet — no verification timestamps,
     * no snapshot, no check rows.
     */
    private function bareDomain(Team $team, ?string $name = null): MonitoredDomain
    {
        $id = Uuid::uuid7();
        $domain = new MonitoredDomain(
            id: $id,
            team: $team,
            domain: ($name ?? 'bare-'.$id->toString()).'.example',
            createdAt: new \DateTimeImmutable('-3 days'),
        );
        $domain->popEvents();
        $this->em()->persist($domain);

        return $domain;
    }

    /**
     * All four protocols verified with a passing snapshot and no reports yet —
     * the classifier's Healthy verdict. Individual cases then break exactly one
     * thing, so each assertion is about that one thing.
     */
    private function healthyDomain(Team $team, ?string $name = null): MonitoredDomain
    {
        $id = Uuid::uuid7();
        $domain = new MonitoredDomain(
            id: $id,
            team: $team,
            domain: ($name ?? 'healthy-'.$id->toString()).'.example',
            createdAt: new \DateTimeImmutable('-10 days'),
            spfVerifiedAt: new \DateTimeImmutable('-9 days'),
            dkimVerifiedAt: new \DateTimeImmutable('-9 days'),
            dmarcVerifiedAt: new \DateTimeImmutable('-9 days'),
        );
        $domain->popEvents();
        $this->em()->persist($domain);

        $this->em()->persist(new DomainHealthSnapshot(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            grade: 'A',
            score: 95,
            spfScore: 100,
            dkimScore: 100,
            dmarcScore: 100,
            mxScore: 95,
            blacklistScore: 100,
            checkedAt: new \DateTimeImmutable('-1 hour'),
        ));

        foreach ([DnsCheckType::Spf, DnsCheckType::Dkim, DnsCheckType::Mx] as $type) {
            $this->check($domain, $type, rawRecord: 'ok', isValid: true, checkedAt: '-2 hours');
        }
        // A real DMARC record pointing rua= at Sendvery, so the 5th "RUA
        // destination" row also reads Configured. A placeholder value would leave
        // report delivery unresolved and every case below would carry an extra
        // reason it is not about.
        $this->check($domain, DnsCheckType::Dmarc, rawRecord: self::DMARC_RECORD_POINTING_AT_SENDVERY, isValid: true, checkedAt: '-2 hours');

        return $domain;
    }

    /**
     * `$checkedAt` defaults to more recent than the baseline rows
     * {@see self::healthyDomain()} writes, so a case that breaks one protocol
     * genuinely wins over the passing row for the same protocol — the state query
     * picks the NEWEST row per protocol, and equal timestamps would make which
     * one wins arbitrary.
     */
    /**
     * A domain the classifier puts in the Attention bucket, by publishing
     * everything except SPF. Used by the verification-nuance cases, which need the
     * domain ON the list before the nuance can ride on its row.
     */
    private function domainNeedingAttention(Team $team, ?string $name = null): MonitoredDomain
    {
        $domain = $this->healthyDomain($team, $name);
        $domain->spfVerifiedAt = null;
        $this->check($domain, DnsCheckType::Spf, rawRecord: null, isValid: false);

        return $domain;
    }

    private function check(
        MonitoredDomain $domain,
        DnsCheckType $type,
        ?string $rawRecord,
        bool $isValid,
        string $checkedAt = '-10 minutes',
    ): void {
        $this->em()->persist(new DnsCheckResult(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            type: $type,
            checkedAt: new \DateTimeImmutable($checkedAt),
            rawRecord: $rawRecord,
            isValid: $isValid,
            issues: [],
            details: [],
            previousRawRecord: null,
            hasChanged: false,
            isFirstCheck: false,
        ));
    }

    private function report(MonitoredDomain $domain, int $pass, int $fail): void
    {
        $report = new DmarcReport(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            reporterOrg: 'google.com',
            reporterEmail: 'noreply@google.com',
            externalReportId: 'ext-'.Uuid::uuid7()->toString(),
            dateRangeBegin: new \DateTimeImmutable('-2 days'),
            dateRangeEnd: new \DateTimeImmutable('-1 day'),
            policyDomain: $domain->domain,
            policyAdkim: DmarcAlignment::Relaxed,
            policyAspf: DmarcAlignment::Relaxed,
            policyP: DmarcPolicy::None,
            policySp: null,
            policyPct: 100,
            rawXml: '<feedback></feedback>',
            processedAt: new \DateTimeImmutable(),
        );
        $this->em()->persist($report);

        $this->em()->persist(new DmarcRecord(
            id: Uuid::uuid7(),
            dmarcReport: $report,
            sourceIp: '1.2.3.4',
            count: $pass,
            disposition: Disposition::None,
            dkimResult: AuthResult::Pass,
            spfResult: AuthResult::Pass,
            headerFrom: $domain->domain,
        ));
        $this->em()->persist(new DmarcRecord(
            id: Uuid::uuid7(),
            dmarcReport: $report,
            sourceIp: '5.6.7.8',
            count: $fail,
            disposition: Disposition::None,
            dkimResult: AuthResult::Fail,
            spfResult: AuthResult::Fail,
            headerFrom: $domain->domain,
        ));
    }

    private function em(): EntityManagerInterface
    {
        return $this->getService(EntityManagerInterface::class);
    }

    private function flush(): void
    {
        $this->em()->flush();
    }
}
