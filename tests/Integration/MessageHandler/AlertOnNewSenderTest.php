<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\Alert;
use App\Entity\DmarcRecord;
use App\Entity\DmarcReport;
use App\Entity\KnownSender;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Events\DmarcReportProcessed;
use App\MessageHandler\AlertOnNewSender;
use App\MessageHandler\UpdateSenderInventoryOnReport;
use App\Tests\IntegrationTestCase;
use App\Tests\ScriptsDnsRecords;
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
 * The new-sender alert was the largest alert category in production — thirteen
 * in thirty days, eleven on a single day — and every one of them described
 * either the customer's own Seznam relay or a recipient-side forwarder. These
 * tests are built from that incident.
 *
 * @see docs/16-sender-identity-and-digest-truthfulness.md (DEC-059 D5, D6, D9, §3.6)
 */
final class AlertOnNewSenderTest extends IntegrationTestCase
{
    use ScriptsDnsRecords;

    private EntityManagerInterface $em;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->em = $this->getService(EntityManagerInterface::class);

        $this->team = new Team(
            id: Uuid::uuid7(),
            name: 'New Sender Alerts',
            slug: 'new-sender-alerts-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable('2026-07-01'),
        );
        $this->team->popEvents();
        $this->em->persist($this->team);
        $this->em->flush();
    }

    #[Test]
    public function aRelayRotatingItsAddressPoolIsOneSenderNotFive(): void
    {
        $domain = $this->givenDomain('sendvery.com');
        $this->givenBaseline($domain);

        $this->scriptReverseDns()
            ->withHostname('2001:db8::1', 'relay-1.bulkmail.example')
            ->withHostname('2001:db8::2', 'relay-2.bulkmail.example')
            ->withHostname('2001:db8::3', 'relay-3.bulkmail.example')
            ->withHostname('2001:db8::4', 'relay-4.bulkmail.example')
            ->withHostname('2001:db8::5', 'relay-5.bulkmail.example');

        $report = $this->givenReport($domain, '2026-07-10 23:59:59');
        $this->givenRecord($report, '2001:db8::1', 2);
        $this->givenRecord($report, '2001:db8::2', 2);
        $this->givenRecord($report, '2001:db8::3', 2);
        $this->givenRecord($report, '2001:db8::4', 1);
        $this->givenRecord($report, '2001:db8::5', 1);

        $this->ingest($report);

        $alerts = $this->alerts();

        self::assertCount(1, $alerts);
        self::assertSame(
            'New sender for sendvery.com: bulkmail.example',
            $alerts[0]->title,
            'Five addresses of one relay are one sender; announcing five is how this alert became the loudest thing in the product.',
        );
        self::assertSame('bulkmail.example (8 messages)', $this->namedSenders($alerts[0]));
        self::assertCount(5, $alerts[0]->data['new_senders'][0]['source_ips']);
    }

    #[Test]
    public function aFreshAddressFromAnAlreadyKnownRelayIsNotANewSender(): void
    {
        $domain = $this->givenDomain('sendvery.com');
        $this->givenBaseline($domain);

        $this->scriptReverseDns()
            ->withHostname('2001:db8::1', 'relay-1.bulkmail.example')
            ->withHostname('2001:db8::6', 'relay-6.bulkmail.example');

        $first = $this->givenReport($domain, '2026-07-10 23:59:59');
        $this->givenRecord($first, '2001:db8::1', 2);
        $this->ingest($first);

        $second = $this->givenReport($domain, '2026-07-11 23:59:59');
        $this->givenRecord($second, '2001:db8::6', 2);
        $this->ingest($second);

        self::assertCount(
            1,
            $this->alerts(),
            'The relay rotated to another address in its pool. That is not a new sender, and treating it as one is unbounded noise.',
        );
    }

    #[Test]
    public function theTeamsOwnSeznamRelayNeverRaisesAnAlertHoweverItRotates(): void
    {
        $domain = $this->givenDomain('sendvery.com');
        $this->givenBaseline($domain);

        $this->scriptReverseDns()
            ->withHostname('77.75.76.89', 'mxb.seznam.cz')
            ->withHostname('2a02:598:1::908', 'mxb-1-908.seznam.cz')
            ->withHostname('2a02:598:2::904', 'mxb-2-904.seznam.cz')
            ->withHostname('2a02:598:3::514', 'mxb-3-514.seznam.cz')
            ->withHostname('77.75.78.89', 'mxb.seznam.cz');

        $report = $this->givenReport($domain, '2026-07-26 23:59:59');
        $this->givenRecord($report, '77.75.76.89', 12, dkim: AuthResult::Pass, spf: AuthResult::Pass);
        $this->givenRecord($report, '2a02:598:1::908', 4, dkim: AuthResult::Pass, spf: AuthResult::Pass);
        $this->givenRecord($report, '2a02:598:2::904', 3, dkim: AuthResult::Pass, spf: AuthResult::Pass);
        $this->givenRecord($report, '2a02:598:3::514', 2, dkim: AuthResult::Pass, spf: AuthResult::Pass);
        $this->givenRecord($report, '77.75.78.89', 1, dkim: AuthResult::Pass, spf: AuthResult::Pass);

        $this->ingest($report);

        self::assertSame(
            [],
            $this->alerts(),
            'A recognised provider carrying the team\'s own mail is a digest line item, never a warning.',
        );
    }

    #[Test]
    public function anAddressAlreadySendingForASiblingDomainIsNotNew(): void
    {
        $this->scriptReverseDns()
            ->withHostname('198.51.100.50', 'relay.partner.example')
            ->withHostname('203.0.113.7', 'gw.house.example')
            ->withHostname('203.0.113.90', 'mailer.newthing.example');

        $sibling = $this->givenDomain('sendvery.com');
        $established = $this->givenReport($sibling, '2026-07-03 23:59:59');
        $this->givenRecord($established, '198.51.100.50', 30);
        $this->ingest($established);

        $domain = $this->givenDomain('mail.myspeedpuzzling.com');
        $baseline = $this->givenReport($domain, '2026-07-09 23:59:59');
        $this->givenRecord($baseline, '203.0.113.7', 5);
        $this->ingest($baseline);

        $report = $this->givenReport($domain, '2026-07-11 23:59:59');
        $this->givenRecord($report, '198.51.100.50', 6);
        $this->givenRecord($report, '203.0.113.90', 4);
        $this->ingest($report);

        $alerts = $this->alerts();

        self::assertCount(1, $alerts);
        self::assertSame(
            'newthing.example (4 messages)',
            $this->namedSenders($alerts[0]),
            'The partner relay has been sending for this team since 3 July; a second domain does not make it a stranger.',
        );
    }

    #[Test]
    public function aDomainsFirstReportEstablishesTheBaselineInSilence(): void
    {
        $domain = $this->givenDomain('brandnew.example');

        $this->scriptReverseDns()
            ->withHostname('203.0.113.11', 'a.mystery.example')
            ->withHostname('203.0.113.12', 'b.othermystery.example');

        $first = $this->givenReport($domain, '2026-07-20 23:59:59');
        $this->givenRecord($first, '203.0.113.11', 4);
        $this->givenRecord($first, '203.0.113.12', 3);

        $this->ingest($first);

        self::assertSame(
            [],
            $this->alerts(),
            'On day one everything looks new, so "5 new senders detected" says nothing except that the domain started working.',
        );

        $second = $this->givenReport($domain, '2026-07-21 23:59:59');
        $this->givenRecord($second, '203.0.113.11', 4);
        $this->ingest($second);

        self::assertSame([], $this->alerts(), 'The senders the first report established stay established.');
    }

    #[Test]
    public function aRecipientSideForwarderIsNotAnAlert(): void
    {
        $domain = $this->givenDomain('sendvery.com');
        $this->givenBaseline($domain);

        $this->scriptReverseDns()
            ->withHostname('52.212.19.177', 'eu.cloud-sec-av.com')
            ->withHostname('15.222.110.90', 'ca.cloud-sec-av.com');

        $report = $this->givenReport($domain, '2026-07-24 23:59:59');
        // The clean forward: DKIM survives the hop, SPF cannot.
        $this->givenRecord($report, '52.212.19.177', 1, dkim: AuthResult::Pass, spf: AuthResult::Fail);
        // The same product rewriting the body, so both checks fail — which on
        // auth results alone is indistinguishable from spoofing.
        $this->givenRecord($report, '15.222.110.90', 1, dkim: AuthResult::Fail, spf: AuthResult::Fail);

        $this->ingest($report);

        self::assertSame(
            [],
            $this->alerts(),
            'A gateway that received legitimate mail and re-injected it is the recipient\'s choice, not the sender\'s problem.',
        );
    }

    #[Test]
    public function aSpooferCannotSilenceThisAlertByNamingItsOwnReverseRecordAfterAGateway(): void
    {
        $domain = $this->givenDomain('sendvery.com');
        $this->givenBaseline($domain);

        // The reverse zone of an IP block belongs to whoever holds the block,
        // and essentially every VPS provider hands that field to the customer.
        // Claiming to be Mimecast therefore costs an attacker one form field —
        // and the forwarder role it used to buy is exactly the role that makes
        // this alert stay quiet. Mimecast's own addresses say otherwise.
        $this->scriptReverseDns()
            ->withForgedHostname('203.0.113.250', 'eu-smtp-delivery-1.mimecast.com')
            ->withForwardAddresses('eu-smtp-delivery-1.mimecast.com', '195.130.217.1');

        $report = $this->givenReport($domain, '2026-07-25 23:59:59');
        $this->givenRecord($report, '203.0.113.250', 40, dkim: AuthResult::Fail, spf: AuthResult::Fail);

        $this->ingest($report);

        $alerts = $this->alerts();

        self::assertCount(
            1,
            $alerts,
            'The new-sender alert exists to surface spoofing; a name its own owner wrote must not be able to switch it off.',
        );
        self::assertSame('New sender for sendvery.com: mimecast.com', $alerts[0]->title);
    }

    #[Test]
    public function aReceiverSayingItOverrodeThePolicyForAForwardIsBelieved(): void
    {
        $domain = $this->givenDomain('sendvery.com');
        $this->givenBaseline($domain);

        // No reverse record and no recognised name: on everything except the
        // receiver's own account, this host scores Suspicious.
        $report = $this->givenReport($domain, '2026-07-26 23:59:59');
        $this->givenRecord(
            $report,
            '203.0.113.77',
            40,
            policyOverrideReasons: [new PolicyOverrideReason(PolicyOverrideReasonType::Forwarded)],
        );

        $this->ingest($report);

        self::assertSame(
            [],
            $this->alerts(),
            'Gmail reporting that it did not apply the policy because the message was relayed is the receiver describing what it did, and no sending host can put that in a report about itself.',
        );
    }

    #[Test]
    public function anArcValidatedOverrideIsAlsoBelievedButOtherLocalPolicyTextIsNot(): void
    {
        $domain = $this->givenDomain('sendvery.com');
        $this->givenBaseline($domain);

        $report = $this->givenReport($domain, '2026-07-26 23:59:59');
        $this->givenRecord(
            $report,
            '203.0.113.78',
            40,
            policyOverrideReasons: [new PolicyOverrideReason(PolicyOverrideReasonType::LocalPolicy, 'arc=pass')],
        );
        $this->givenRecord(
            $report,
            '203.0.113.79',
            40,
            policyOverrideReasons: [new PolicyOverrideReason(PolicyOverrideReasonType::LocalPolicy, 'sender on our allow list')],
        );

        $this->ingest($report);

        $alerts = $this->alerts();

        self::assertCount(1, $alerts, 'A verified ARC chain says a relay handled the message; an unexplained local exemption says nothing about routing.');
        self::assertSame('New sender for sendvery.com: 203.0.113.79', $alerts[0]->title);
    }

    #[Test]
    public function anOverrideAboutSamplingDoesNotSilenceTheAlert(): void
    {
        $domain = $this->givenDomain('sendvery.com');
        $this->givenBaseline($domain);

        $report = $this->givenReport($domain, '2026-07-26 23:59:59');
        $this->givenRecord(
            $report,
            '203.0.113.80',
            40,
            policyOverrideReasons: [new PolicyOverrideReason(PolicyOverrideReasonType::SampledOut, 'pct=50')],
        );

        $this->ingest($report);

        self::assertCount(
            1,
            $this->alerts(),
            'sampled_out says the message fell outside the pct= sample. It says nothing about who relayed it.',
        );
    }

    #[Test]
    public function anUnrecognisedSenderStillRaisesAnAlertNamingItAndItsVolume(): void
    {
        $domain = $this->givenDomain('sendvery.com');
        $this->givenBaseline($domain);

        $this->scriptReverseDns()->withHostname('203.0.113.200', 'smtp.strangehost.example');

        $report = $this->givenReport($domain, '2026-07-25 23:59:59');
        $this->givenRecord($report, '203.0.113.200', 1);

        $this->ingest($report);

        $alerts = $this->alerts();

        self::assertCount(1, $alerts, 'Silencing the explainable senders is only worth doing if the unexplained one still gets through.');
        self::assertSame('New sender for sendvery.com: strangehost.example', $alerts[0]->title);
        self::assertStringContainsString('strangehost.example (1 message)', $alerts[0]->message);
        self::assertStringContainsString('nothing is blocked either way', $alerts[0]->message);
        self::assertStringNotContainsString('203.0.113.200', $alerts[0]->message, 'A raw address tells the reader nothing they can act on.');
    }

    #[Test]
    public function anAddressWithNoReverseRecordIsNamedByItsAddressBecauseThereIsNothingElse(): void
    {
        $domain = $this->givenDomain('sendvery.com');
        $this->givenBaseline($domain);

        $report = $this->givenReport($domain, '2026-07-25 23:59:59');
        $this->givenRecord($report, '203.0.113.222', 3);

        $this->ingest($report);

        $alerts = $this->alerts();

        self::assertCount(1, $alerts);
        self::assertSame('203.0.113.222 (3 messages)', $this->namedSenders($alerts[0]));
    }

    #[Test]
    public function vouchingForOneAddressOfARelayVouchesForTheWholePool(): void
    {
        $domain = $this->givenDomain('sendvery.com');
        $this->givenBaseline($domain);

        $this->scriptReverseDns()
            ->withHostname('198.51.100.60', 'out-1.ourmailer.example')
            ->withHostname('198.51.100.61', 'out-2.ourmailer.example');

        $this->em->persist(new KnownSender(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            sourceIp: '198.51.100.60',
            firstSeenAt: new \DateTimeImmutable('2026-07-01 00:00:00'),
            lastSeenAt: new \DateTimeImmutable('2026-07-01 00:00:00'),
            totalMessages: 20,
            passRate: 100.0,
            isAuthorized: true,
        ));

        $report = $this->givenReport($domain, '2026-07-22 23:59:59');
        $this->givenRecord($report, '198.51.100.60', 10, dkim: AuthResult::Pass, spf: AuthResult::Pass);
        $this->givenRecord($report, '198.51.100.61', 2);

        $this->ingest($report);

        self::assertSame(
            [],
            $this->alerts(),
            'The operator said this relay is theirs. Alerting every time it adds an address re-creates exactly the noise this alert was fixed to stop.',
        );
    }

    #[Test]
    public function namesAtMostFiveSendersAndCountsTheRest(): void
    {
        $domain = $this->givenDomain('sendvery.com');
        $this->givenBaseline($domain);

        $report = $this->givenReport($domain, '2026-07-23 23:59:59');
        $reverseDns = $this->scriptReverseDns();

        foreach (range(1, 6) as $index) {
            $ip = sprintf('203.0.113.%d', 100 + $index);
            $reverseDns->withHostname($ip, sprintf('mail.stranger%d.example', $index));
            $this->givenRecord($report, $ip, 2);
        }

        $this->ingest($report);

        $alerts = $this->alerts();

        self::assertCount(1, $alerts);
        self::assertSame('6 new senders detected for sendvery.com', $alerts[0]->title);
        self::assertStringContainsString('and 1 more', $alerts[0]->message);
        self::assertStringContainsString('They are listed as "Needs review"', $alerts[0]->message);
        self::assertCount(6, $alerts[0]->data['new_senders']);
    }

    #[Test]
    public function anOversizedReverseDnsAnswerCannotBreakReportIngest(): void
    {
        $domain = $this->givenDomain('sendvery.com');
        $this->givenBaseline($domain);

        // Underscores make this unusable as a registrable domain, so the whole
        // 250-character name becomes the sender's name — and whoever runs the
        // reverse zone chose every one of those characters.
        $hostile = 'weird_host.'.str_repeat('z', 239);

        $this->scriptReverseDns()->withHostname('203.0.113.240', $hostile);

        $report = $this->givenReport($domain, '2026-07-25 23:59:59');
        $this->givenRecord($report, '203.0.113.240', 3);

        $this->ingest($report);

        $alerts = $this->alerts();

        self::assertCount(1, $alerts);
        self::assertSame(
            255,
            mb_strlen($alerts[0]->title),
            'A report whose sender name overflows the column would abort the ingest transaction, and every retry would abort identically.',
        );
    }

    #[Test]
    public function aReportThatDescribesNoMailRaisesNothing(): void
    {
        $domain = $this->givenDomain('sendvery.com');
        $this->givenBaseline($domain);

        $this->ingest($this->givenReport($domain, '2026-07-23 23:59:59'));

        self::assertSame([], $this->alerts());
    }

    /**
     * Runs the report through both handlers that `DmarcReportProcessed` fans
     * out to, in the order the bus invokes them, and flushes once at the end
     * exactly like the `doctrine_transaction` middleware does.
     *
     * The alert cannot be exercised in isolation: what a sender *is* lives in
     * the shared `sender_identity` cache, and sender discovery is the other
     * writer of that cache. Running only one of the two would test a pipeline
     * that never exists — and would miss the fact that both handlers resolve
     * the same brand-new address inside one unflushed transaction.
     */
    private function ingest(DmarcReport $report): void
    {
        $this->em->flush();

        $event = new DmarcReportProcessed(
            reportId: $report->id,
            domainId: $report->monitoredDomain->id,
            reporterOrg: 'google.com',
            totalRecords: 1,
            passCount: 0,
            failCount: 0,
        );

        $this->getService(AlertOnNewSender::class)($event);
        $this->getService(UpdateSenderInventoryOnReport::class)($event);

        $this->em->flush();
    }

    private function givenDomain(string $name): MonitoredDomain
    {
        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $this->team,
            domain: $name,
            createdAt: new \DateTimeImmutable('2026-07-01'),
        );
        $domain->popEvents();
        $this->em->persist($domain);
        $this->em->flush();

        return $domain;
    }

    /**
     * An earlier report so the domain is past its first-report baseline. Its
     * one sender is deliberately left unresolvable: it must not become part of
     * any identity a test then asserts on.
     */
    private function givenBaseline(MonitoredDomain $domain): void
    {
        $baseline = $this->givenReport($domain, '2026-07-02 23:59:59');
        $this->givenRecord($baseline, '192.0.2.1', 5);
        $this->em->flush();
    }

    private function givenReport(MonitoredDomain $domain, string $periodEnd): DmarcReport
    {
        $report = new DmarcReport(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            reporterOrg: 'google.com',
            reporterEmail: 'noreply-dmarc-support@google.com',
            externalReportId: 'alert-'.Uuid::uuid7()->toString(),
            dateRangeBegin: (new \DateTimeImmutable($periodEnd))->modify('-1 day'),
            dateRangeEnd: new \DateTimeImmutable($periodEnd),
            policyDomain: $domain->domain,
            policyAdkim: DmarcAlignment::Relaxed,
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
     * @param list<PolicyOverrideReason> $policyOverrideReasons
     */
    private function givenRecord(
        DmarcReport $report,
        string $sourceIp,
        int $count,
        AuthResult $dkim = AuthResult::Fail,
        AuthResult $spf = AuthResult::Fail,
        array $policyOverrideReasons = [],
    ): void {
        $this->em->persist(new DmarcRecord(
            id: Uuid::uuid7(),
            dmarcReport: $report,
            sourceIp: $sourceIp,
            count: $count,
            disposition: Disposition::None,
            dkimResult: $dkim,
            spfResult: $spf,
            headerFrom: $report->monitoredDomain->domain,
            policyOverrideReasons: $policyOverrideReasons,
        ));
    }

    /**
     * @return list<Alert>
     */
    private function alerts(): array
    {
        return $this->em->getRepository(Alert::class)
            ->createQueryBuilder('a')
            ->where('a.team = :team')
            ->setParameter('team', $this->team)
            ->orderBy('a.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The sender list as the reader sees it, without the surrounding copy.
     */
    private function namedSenders(Alert $alert): string
    {
        $matched = preg_match('/seen (.+) sending as /', $alert->message, $matches);

        self::assertSame(1, $matched, sprintf('Expected the alert to name its senders: %s', $alert->message));

        return $matches[1];
    }
}
