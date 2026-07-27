<?php

declare(strict_types=1);

namespace App\Tests\Integration\Services;

use App\Entity\DmarcRecord;
use App\Entity\DmarcReport;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Services\ReportSenderSignals;
use App\Tests\IntegrationTestCase;
use App\Value\AuthResult;
use App\Value\Disposition;
use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;
use App\Value\PolicyOverrideReason;
use App\Value\PolicyOverrideReasonType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;

/**
 * The one place a report's per-sender evidence is gathered (DEC-060 WP-A/WP-B).
 *
 * @see docs/18-forwarder-trust-verification-plan.md
 */
final class ReportSenderSignalsTest extends IntegrationTestCase
{
    private EntityManagerInterface $em;

    private ReportSenderSignals $signals;

    private MonitoredDomain $domain;

    protected function setUp(): void
    {
        parent::setUp();

        $this->em = $this->getService(EntityManagerInterface::class);
        $this->signals = $this->getService(ReportSenderSignals::class);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Report Sender Signals',
            slug: 'report-sender-signals-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable('2026-07-01'),
        );
        $team->popEvents();
        $this->em->persist($team);

        $this->domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'sendvery.com',
            createdAt: new \DateTimeImmutable('2026-07-01'),
        );
        $this->domain->popEvents();
        $this->em->persist($this->domain);
        $this->em->flush();
    }

    #[Test]
    public function sumsOneHostsVolumeAndPassesAcrossEveryRecordItAppearsIn(): void
    {
        $report = $this->givenReport();
        $this->givenRecord($report, '203.0.113.10', 6, AuthResult::Pass, AuthResult::Pass);
        $this->givenRecord($report, '203.0.113.10', 4, AuthResult::Fail, AuthResult::Fail);
        $this->givenRecord($report, '203.0.113.11', 2, AuthResult::Fail, AuthResult::Fail);
        $this->em->flush();

        $signals = $this->signals->forReport($report->id);

        self::assertSame(10, $signals['203.0.113.10']->totalMessages);
        self::assertSame(60.0, $signals['203.0.113.10']->dkimPassRate);
        self::assertSame(60.0, $signals['203.0.113.10']->spfPassRate);
        self::assertSame(2, $signals['203.0.113.11']->totalMessages);
    }

    #[Test]
    public function countsOnlyTheSignaturesMadeForTheDomainInTheFromHeader(): void
    {
        $report = $this->givenReport();

        // A relayed newsletter: the vendor's signature verifies, for the
        // vendor's domain. It says nothing about sendvery.com.
        $this->givenRecord($report, '203.0.113.20', 5, AuthResult::Pass, AuthResult::Fail, dkimDomain: 'comcastmailservice.net');
        // The clean forward: the original domain's own signature survived.
        $this->givenRecord($report, '203.0.113.21', 3, AuthResult::Pass, AuthResult::Fail, dkimDomain: 'sendvery.com');
        $this->em->flush();

        $signals = $this->signals->forReport($report->id);

        self::assertSame(0, $signals['203.0.113.20']->alignedDkimPassCount);
        self::assertSame(
            100.0,
            $signals['203.0.113.20']->dkimPassRate,
            'The plain pass rate still records what the reporter said; only the aligned count refuses to read it as proof about this domain.',
        );
        self::assertSame(3, $signals['203.0.113.21']->alignedDkimPassCount);
    }

    #[Test]
    public function readsAlignmentTheWayTheDomainAskedForIt(): void
    {
        $relaxed = $this->givenReport();
        $this->givenRecord($relaxed, '203.0.113.30', 3, AuthResult::Pass, AuthResult::Fail, dkimDomain: 'mail.sendvery.com');

        $strict = $this->givenReport(adkim: DmarcAlignment::Strict);
        $this->givenRecord($strict, '203.0.113.30', 3, AuthResult::Pass, AuthResult::Fail, dkimDomain: 'mail.sendvery.com');
        $this->em->flush();

        self::assertSame(
            3,
            $this->signals->forReport($relaxed->id)['203.0.113.30']->alignedDkimPassCount,
            'Relaxed alignment shares an organisational domain, which is the rule every other surface already uses.',
        );
        self::assertSame(
            0,
            $this->signals->forReport($strict->id)['203.0.113.30']->alignedDkimPassCount,
            'A domain that asked for strict alignment gets strict alignment.',
        );
    }

    #[Test]
    public function ignoresAnAlignedSigningDomainOnASignatureThatDidNotVerify(): void
    {
        $report = $this->givenReport();
        $this->givenRecord($report, '203.0.113.40', 8, AuthResult::Fail, AuthResult::Fail, dkimDomain: 'sendvery.com');
        $this->em->flush();

        self::assertSame(
            0,
            $this->signals->forReport($report->id)['203.0.113.40']->alignedDkimPassCount,
            'The rule rests on a signature that verified. A `d=` naming the right domain on a broken signature is a claim, not a proof.',
        );
    }

    #[Test]
    public function noticesAReturnPathTheRelayReplacedOnItsWayThrough(): void
    {
        $report = $this->givenReport();
        $this->givenRecord($report, '203.0.113.50', 12, AuthResult::Fail, AuthResult::Fail, spfDomain: 'srs.gateway.example');
        // The same shape under the sender's own domain is an ordinary bounce
        // mailbox, and it aligns, so nothing was rewritten.
        $this->givenRecord($report, '203.0.113.51', 12, AuthResult::Fail, AuthResult::Fail, spfDomain: 'bounces.sendvery.com');
        // A non-aligned envelope with no rewriting marks is a plain alignment
        // failure.
        $this->givenRecord($report, '203.0.113.52', 12, AuthResult::Fail, AuthResult::Fail, spfDomain: 'mail.stranger.example');
        $this->em->flush();

        $signals = $this->signals->forReport($report->id);

        self::assertSame(12, $signals['203.0.113.50']->rewrittenEnvelopeMessageCount);
        self::assertSame(0, $signals['203.0.113.51']->rewrittenEnvelopeMessageCount);
        self::assertSame(0, $signals['203.0.113.52']->rewrittenEnvelopeMessageCount);
    }

    #[Test]
    public function gathersTheReceiversOverrideReasonsAcrossAllOfAHostsRecords(): void
    {
        $report = $this->givenReport();
        $this->givenRecord($report, '203.0.113.60', 3, AuthResult::Fail, AuthResult::Fail, dkimDomain: 'a.example', reasons: [
            new PolicyOverrideReason(PolicyOverrideReasonType::SampledOut, 'pct=50'),
        ]);
        $this->givenRecord($report, '203.0.113.60', 3, AuthResult::Fail, AuthResult::Fail, dkimDomain: 'b.example', reasons: [
            new PolicyOverrideReason(PolicyOverrideReasonType::Forwarded),
        ]);
        $this->em->flush();

        $forwarding = $this->signals->forReport($report->id)['203.0.113.60']->forwarding;

        self::assertTrue(
            $forwarding->attestsForwarding,
            'One host is described by many records, and the attestation may sit on any of them.',
        );
        self::assertSame(PolicyOverrideReasonType::Forwarded, $forwarding->attestedBy);
    }

    #[Test]
    public function noticesAHostCarryingASignedStreamThatPassesFromSomewhereElse(): void
    {
        $origin = $this->givenReport();
        $this->givenRecord($origin, '203.0.113.80', 40, AuthResult::Pass, AuthResult::Pass, dkimDomain: 'sendvery.com');
        $this->em->flush();

        $relay = $this->givenReport();
        $this->givenRecord($relay, '203.0.113.81', 3, AuthResult::Fail, AuthResult::Fail, dkimDomain: 'sendvery.com');
        // A different stream entirely: nothing else in the window signs for it.
        $this->givenRecord($relay, '203.0.113.82', 3, AuthResult::Fail, AuthResult::Fail, dkimDomain: 'nowhere.example');
        $this->em->flush();

        $signals = $this->signals->forReport($relay->id);

        self::assertTrue(
            $signals['203.0.113.81']->signedStreamSeenFromAnotherHost,
            'The same signed stream passing from one address and failing from another is what a relay looks like.',
        );
        self::assertFalse($signals['203.0.113.82']->signedStreamSeenFromAnotherHost);
    }

    #[Test]
    public function doesNotLetAHostCorroborateItself(): void
    {
        $report = $this->givenReport();
        $this->givenRecord($report, '203.0.113.90', 40, AuthResult::Pass, AuthResult::Pass, dkimDomain: 'sendvery.com');
        $this->givenRecord($report, '203.0.113.90', 3, AuthResult::Fail, AuthResult::Fail, dkimDomain: 'sendvery.com');
        $this->em->flush();

        self::assertFalse(
            $this->signals->forReport($report->id)['203.0.113.90']->signedStreamSeenFromAnotherHost,
            'One address sending some mail that passes and some that fails is an ordinary sender having an ordinary day, not a relay.',
        );
    }

    #[Test]
    public function willNotCorrelateWithAStreamFromOutsideTheWindow(): void
    {
        $ancient = $this->givenReport(periodEnd: '2026-05-01 00:00:00');
        $this->givenRecord($ancient, '203.0.113.100', 40, AuthResult::Pass, AuthResult::Pass, dkimDomain: 'sendvery.com');
        $this->em->flush();

        $recent = $this->givenReport();
        $this->givenRecord($recent, '203.0.113.101', 3, AuthResult::Fail, AuthResult::Fail, dkimDomain: 'sendvery.com');
        $this->em->flush();

        self::assertFalse(
            $this->signals->forReport($recent->id)['203.0.113.101']->signedStreamSeenFromAnotherHost,
            'A signing domain retired months ago cannot vouch for a host sending today.',
        );
    }

    #[Test]
    public function marksOnlyTheAddressesTheCallerVouchedFor(): void
    {
        $report = $this->givenReport();
        $this->givenRecord($report, '203.0.113.70', 3, AuthResult::Pass, AuthResult::Pass);
        $this->givenRecord($report, '203.0.113.71', 3, AuthResult::Pass, AuthResult::Pass);
        $this->em->flush();

        $signals = $this->signals->forReport($report->id, ['203.0.113.70']);

        self::assertTrue($signals['203.0.113.70']->isAuthorized);
        self::assertFalse($signals['203.0.113.71']->isAuthorized);
    }

    #[Test]
    public function aReportDescribingNoMailYieldsNoSenders(): void
    {
        $report = $this->givenReport();
        $this->em->flush();

        self::assertSame([], $this->signals->forReport($report->id));
    }

    private function givenReport(
        DmarcAlignment $adkim = DmarcAlignment::Relaxed,
        string $periodEnd = '2026-07-26 00:00:00',
    ): DmarcReport {
        $report = new DmarcReport(
            id: Uuid::uuid7(),
            monitoredDomain: $this->domain,
            reporterOrg: 'google.com',
            reporterEmail: 'noreply-dmarc-support@google.com',
            externalReportId: 'signals-'.Uuid::uuid7()->toString(),
            dateRangeBegin: new \DateTimeImmutable($periodEnd)->modify('-1 day'),
            dateRangeEnd: new \DateTimeImmutable($periodEnd),
            policyDomain: $this->domain->domain,
            policyAdkim: $adkim,
            policyAspf: DmarcAlignment::Relaxed,
            policyP: DmarcPolicy::None,
            policySp: null,
            policyPct: 100,
            rawXml: '<feedback></feedback>',
            processedAt: new \DateTimeImmutable('2026-07-27 06:00:00'),
        );
        $report->popEvents();
        $this->em->persist($report);

        return $report;
    }

    /**
     * @param list<PolicyOverrideReason> $reasons
     */
    private function givenRecord(
        DmarcReport $report,
        string $sourceIp,
        int $count,
        AuthResult $dkim,
        AuthResult $spf,
        ?string $dkimDomain = null,
        ?string $spfDomain = null,
        array $reasons = [],
    ): void {
        $this->em->persist(new DmarcRecord(
            id: Uuid::uuid7(),
            dmarcReport: $report,
            sourceIp: $sourceIp,
            count: $count,
            disposition: Disposition::None,
            dkimResult: $dkim,
            spfResult: $spf,
            headerFrom: $this->domain->domain,
            dkimDomain: $dkimDomain,
            spfDomain: $spfDomain,
            policyOverrideReasons: $reasons,
        ));
    }
}
