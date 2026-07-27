<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\Alert;
use App\Entity\DmarcRecord;
use App\Entity\DmarcReport;
use App\Entity\KnownSender;
use App\Entity\MonitoredDomain;
use App\Entity\SenderIdentity;
use App\Message\SendWeeklyDigest;
use App\MessageHandler\SendWeeklyDigestHandler;
use App\Services\Digest\WeeklyDigestGenerator;
use App\Tests\Fixtures\Persona;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use App\Value\AlertSeverity;
use App\Value\AlertType;
use App\Value\AuthResult;
use App\Value\Disposition;
use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;
use App\Value\SenderRole;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Mime\Email;

/**
 * What the weekly digest email actually says. The digest is rendered by a cron
 * command with no HTTP request behind it, which is why link generation and
 * "how much noise do we put in front of the reader" both need locking down here
 * rather than in a controller test.
 */
final class WeeklyDigestEmailContentTest extends WebTestCase
{
    #[Test]
    public function everyLinkPointsAtThePublicBaseUrlRatherThanLocalhost(): void
    {
        // The digest is produced outside any HTTP request, so absolute URLs come
        // from the router's configured default URI. Without that configuration
        // Symfony falls back to "http://localhost/" and every button in the
        // email is dead for the customer who receives it.
        $persona = $this->onboardedPersona();

        $html = $this->renderDigest($persona);

        self::assertStringContainsString('https://sendvery.test/app', $html);
        self::assertStringNotContainsString(
            'http://localhost',
            $html,
            'Digest links must never fall back to localhost.',
        );
    }

    #[Test]
    public function aDomainWithNoReportsSaysItIsWaitingRatherThanShowingZeroPercent(): void
    {
        $persona = $this->onboardedPersona();

        $html = $this->renderDigest($persona);

        self::assertStringContainsString('Waiting for first report', $html);
        self::assertStringNotContainsString(
            '0.0%',
            $html,
            'A domain with no reports must not be shown as failing every message.',
        );
    }

    #[Test]
    public function repeatedDetectionsForOneDomainCollapseIntoASingleRowWithACount(): void
    {
        // Eleven separate "N new sender(s) detected" alerts for one domain used
        // to produce eleven near-identical amber rows in the email.
        $persona = $this->onboardedPersona();
        $this->seedAlerts($persona, AlertType::NewUnknownSender, AlertSeverity::Warning, count: 11);

        $html = $this->renderDigest($persona);

        self::assertStringContainsString('11× this week', $html);
        self::assertSame(
            1,
            substr_count($html, 'new sender(s) detected'),
            'Repeated detections for one domain must be summarised in one row.',
        );
    }

    #[Test]
    public function noMoreThanAHandfulOfProblemsAreListedAndTheRestAreOneClickAway(): void
    {
        $persona = $this->onboardedPersona();
        // Distinct alert types so nothing collapses — each is its own group.
        foreach ([
            AlertType::DnsRecordChanged,
            AlertType::DnsRecordInvalid,
            AlertType::DnsRecordMissing,
            AlertType::IpBlacklisted,
            AlertType::FailureSpike,
            AlertType::NewUnknownSender,
            AlertType::PolicyRecommendation,
        ] as $index => $type) {
            $this->seedAlerts($persona, $type, AlertSeverity::Warning, count: 1, titleSuffix: "group-{$index}");
        }

        $html = $this->renderDigest($persona);

        $shown = 0;
        for ($index = 0; $index < 7; ++$index) {
            $shown += substr_count($html, "group-{$index}");
        }

        self::assertSame(
            WeeklyDigestGenerator::ATTENTION_ALERTS_LIMIT,
            $shown,
            'The digest must show only the few most important problems.',
        );
        self::assertStringContainsString('see all alerts', $html);
        self::assertStringContainsString('https://sendvery.test/app/alerts', $html);
    }

    #[Test]
    public function criticalProblemsAreListedAheadOfMilderOnes(): void
    {
        $persona = $this->onboardedPersona();
        $this->seedAlerts($persona, AlertType::PolicyRecommendation, AlertSeverity::Info, count: 1, titleSuffix: 'the-info-one');
        $this->seedAlerts($persona, AlertType::DnsRecordMissing, AlertSeverity::Critical, count: 1, titleSuffix: 'the-critical-one');

        $html = $this->renderDigest($persona);

        self::assertLessThan(
            strpos($html, 'the-info-one'),
            strpos($html, 'the-critical-one'),
            'A critical problem must be the first thing the reader sees.',
        );
    }

    #[Test]
    public function problemsThatAreAlreadyFixedAreReportedAsGoodNewsNotAsOutstandingWork(): void
    {
        $persona = $this->onboardedPersona();
        $this->seedAlerts(
            $persona,
            AlertType::DnsRecordMissing,
            AlertSeverity::Critical,
            count: 1,
            titleSuffix: 'already-fixed',
            resolved: true,
        );

        $html = $this->renderDigest($persona);

        self::assertStringNotContainsString(
            'already-fixed',
            $html,
            'A resolved problem must not be listed as needing attention.',
        );
        self::assertStringContainsString('issue resolved this week', $html);
    }

    #[Test]
    public function goodNewsAlertsAreNotDressedUpAsProblems(): void
    {
        // Publishing a DNS record for the first time is the outcome the setup
        // flow asks for — it must never appear in the attention list.
        $persona = $this->onboardedPersona();
        $this->seedAlerts(
            $persona,
            AlertType::DnsRecordPublished,
            AlertSeverity::Success,
            count: 1,
            titleSuffix: 'spf-now-published',
        );

        $html = $this->renderDigest($persona);

        self::assertStringNotContainsString('spf-now-published', $html);
        self::assertStringNotContainsString('Needs your attention', $html);
    }

    #[Test]
    public function newSendersAreListedPerDomainWithATotalAndACappedList(): void
    {
        $persona = $this->onboardedPersona();
        assert(null !== $persona->domain);
        $this->seedReportWithSenders($persona->domain, senderCount: 11);

        $html = $this->renderDigest($persona);

        self::assertStringContainsString('New senders discovered', $html);
        self::assertStringContainsString('· 11 new', $html);
        self::assertStringContainsString(
            '+'.(11 - WeeklyDigestGenerator::NEW_SENDERS_PER_DOMAIN_LIMIT).' more',
            $html,
            'One chatty domain must not push the rest of the digest off the screen.',
        );
        self::assertStringContainsString(
            'and '.(11 - WeeklyDigestGenerator::NEW_SENDERS_PER_DOMAIN_LIMIT).' more',
            $this->renderDigestText($persona),
            'The plain-text alternative must hide exactly as much as the HTML one.',
        );
    }

    /**
     * The complaint that produced DEC-059: a wall of raw addresses, all tinted
     * identically, so a recipient-side security gateway that failed four
     * forwarded messages was indistinguishable from an attack.
     */
    #[Test]
    public function aMailGatewayIsNamedAndExplainedInsteadOfAppearingAsRawAddresses(): void
    {
        $persona = $this->onboardedPersona();
        assert(null !== $persona->domain);

        foreach (['52.212.19.177' => 'eu', '15.222.110.90' => 'ca', '35.174.145.124' => 'us'] as $ip => $region) {
            $this->seedIdentity($ip, $region.'.cloud-sec-av.com', 'cloud-sec-av.com', null, SenderRole::Forwarder);
        }

        $this->seedReport($persona->domain, [
            ['ip' => '52.212.19.177', 'count' => 2, 'passes' => false],
            ['ip' => '15.222.110.90', 'count' => 1, 'passes' => false],
            ['ip' => '35.174.145.124', 'count' => 1, 'passes' => false],
        ]);

        $html = $this->renderDigest($persona);

        self::assertStringContainsString('cloud-sec-av.com', $html);
        self::assertStringContainsString(SenderRole::Forwarder->label(), $html);
        self::assertStringContainsString('4 messages', $html, 'The three nodes are one sender with one combined volume.');
        self::assertStringNotContainsString('52.212.19.177', $html, 'The reader must never be shown the raw addresses.');
        self::assertStringContainsString('· 1 new', $html, 'One gateway is one discovery, not three.');
    }

    #[Test]
    public function theHeadlinePassRateReflectsEveryMessageRatherThanEveryDomainEqually(): void
    {
        $persona = $this->onboardedPersona();
        assert(null !== $persona->domain);
        $busy = $this->addDomain($persona, 'busy-digest.example');

        $this->seedReport($persona->domain, [['ip' => '198.51.100.1', 'count' => 10, 'passes' => true]]);
        $this->seedReport($busy, [
            ['ip' => '198.51.100.2', 'count' => 45, 'passes' => true],
            ['ip' => '198.51.100.3', 'count' => 2, 'passes' => false],
        ]);

        $html = $this->renderDigest($persona);

        self::assertStringContainsString('96.5%', $html, '55 of 57 messages passed.');
        self::assertStringNotContainsString(
            '97.9%',
            $html,
            'Averaging the two domain rates would claim 97.9% while the sentence beside it talks about all 57 messages.',
        );
    }

    #[Test]
    public function thePlainTextDigestAlsoSaysWhatEachNewSenderIs(): void
    {
        // Plenty of readers get the text/plain alternative. It has to carry the
        // same reassurance, not a bare list of names.
        $persona = $this->onboardedPersona();
        assert(null !== $persona->domain);
        $this->seedIdentity('77.75.78.89', 'mxb.seznam.cz', 'seznam.cz', 'Seznam', SenderRole::Esp);
        $this->seedReport($persona->domain, [['ip' => '77.75.78.89', 'count' => 47, 'passes' => true]]);

        $text = $this->renderDigestText($persona);

        self::assertStringContainsString('Overall pass rate: 100.0%', $text);
        self::assertStringContainsString('New senders (1):', $text);
        self::assertStringContainsString('Seznam — '.SenderRole::Esp->label().', 47 messages, 100.0% pass', $text);
    }

    #[Test]
    public function thePlainTextDigestReportsTheTrendAndTheSendersStillWaitingForADecision(): void
    {
        $persona = $this->onboardedPersona();
        assert(null !== $persona->domain);
        $em = $this->getService(EntityManagerInterface::class);

        // Half of last week's mail failed; all of this week's passed.
        $this->seedReport($persona->domain, [
            ['ip' => '198.51.100.20', 'count' => 5, 'passes' => true],
            ['ip' => '198.51.100.21', 'count' => 5, 'passes' => false],
        ], periodEnd: new \DateTimeImmutable('-10 days'));
        $this->seedReport($persona->domain, [['ip' => '198.51.100.20', 'count' => 10, 'passes' => true]]);

        // More unreviewed senders than the digest will name, so the text has to
        // account for the ones it did not print.
        for ($index = 0; $index < WeeklyDigestGenerator::UNREVIEWED_SENDERS_PER_DOMAIN_LIMIT + 2; ++$index) {
            $em->persist(new KnownSender(
                id: Uuid::uuid7(),
                monitoredDomain: $persona->domain,
                sourceIp: '203.0.113.'.(50 + $index),
                firstSeenAt: new \DateTimeImmutable('-30 days'),
                lastSeenAt: new \DateTimeImmutable('-1 day'),
                totalMessages: 100 - $index,
                passRate: 100.0,
            ));
        }

        $text = $this->renderDigestText($persona);

        self::assertStringContainsString('Trend: +50.0%', $text);
        self::assertStringContainsString(
            'Waiting for your review ('.(WeeklyDigestGenerator::UNREVIEWED_SENDERS_PER_DOMAIN_LIMIT + 2).',',
            $text,
        );
        self::assertStringContainsString('and 2 more', $text, 'The count of unnamed senders must survive into plain text.');
    }

    private function onboardedPersona(): Persona
    {
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $suffix = substr(uniqid('', true), -6);

        return $fixtures->persona()
            ->emailPrefix('digest-content-'.$suffix)
            ->teamName('Digest Content '.$suffix)
            ->withDomain('digest-'.$suffix.'.example')
            ->build();
    }

    private function renderDigest(Persona $persona): string
    {
        return (string) $this->sendDigest($persona)->getHtmlBody();
    }

    private function renderDigestText(Persona $persona): string
    {
        return (string) $this->sendDigest($persona)->getTextBody();
    }

    private function sendDigest(Persona $persona): Email
    {
        $em = $this->getService(EntityManagerInterface::class);
        $em->flush();

        $handler = $this->getService(SendWeeklyDigestHandler::class);
        $handler(new SendWeeklyDigest(teamId: $persona->team->id));

        $messages = self::getMailerMessages();
        self::assertNotSame([], $messages, 'Expected the digest to be sent to the team owner.');
        $message = $messages[0];
        self::assertInstanceOf(Email::class, $message);

        return $message;
    }

    private function addDomain(Persona $persona, string $name): MonitoredDomain
    {
        $em = $this->getService(EntityManagerInterface::class);

        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $persona->team,
            domain: $name,
            createdAt: new \DateTimeImmutable(),
        );
        $domain->popEvents();
        $em->persist($domain);

        return $domain;
    }

    private function seedIdentity(
        string $sourceIp,
        string $hostname,
        ?string $registrableDomain,
        ?string $organization,
        SenderRole $role,
    ): void {
        $this->getService(EntityManagerInterface::class)->persist(new SenderIdentity(
            id: Uuid::uuid7(),
            sourceIp: $sourceIp,
            resolvedAt: new \DateTimeImmutable(),
            hostname: $hostname,
            registrableDomain: $registrableDomain,
            organization: $organization,
            role: $role,
            resolutionAttempts: 1,
            lastAttemptAt: new \DateTimeImmutable(),
        ));
    }

    /**
     * @param list<array{ip: string, count: int, passes: bool}> $records
     */
    private function seedReport(MonitoredDomain $domain, array $records, ?\DateTimeImmutable $periodEnd = null): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $report = $this->newReport($domain, $periodEnd ?? new \DateTimeImmutable('-1 day'));
        $em->persist($report);

        foreach ($records as $record) {
            $em->persist(new DmarcRecord(
                id: Uuid::uuid7(),
                dmarcReport: $report,
                sourceIp: $record['ip'],
                count: $record['count'],
                disposition: Disposition::None,
                dkimResult: $record['passes'] ? AuthResult::Pass : AuthResult::Fail,
                spfResult: $record['passes'] ? AuthResult::Pass : AuthResult::Fail,
                headerFrom: $domain->domain,
            ));
        }
    }

    private function newReport(MonitoredDomain $domain, \DateTimeImmutable $periodEnd): DmarcReport
    {
        return new DmarcReport(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            reporterOrg: 'google.com',
            reporterEmail: 'noreply@google.com',
            externalReportId: 'ext-'.Uuid::uuid7()->toString(),
            dateRangeBegin: $periodEnd->modify('-1 day'),
            dateRangeEnd: $periodEnd,
            policyDomain: $domain->domain,
            policyAdkim: DmarcAlignment::Relaxed,
            policyAspf: DmarcAlignment::Relaxed,
            policyP: DmarcPolicy::None,
            policySp: null,
            policyPct: 100,
            rawXml: '<feedback></feedback>',
            processedAt: new \DateTimeImmutable(),
        );
    }

    private function seedAlerts(
        Persona $persona,
        AlertType $type,
        AlertSeverity $severity,
        int $count,
        string $titleSuffix = 'new sender(s) detected',
        bool $resolved = false,
    ): void {
        $em = $this->getService(EntityManagerInterface::class);

        for ($index = 0; $index < $count; ++$index) {
            $alert = new Alert(
                id: Uuid::uuid7(),
                team: $persona->team,
                monitoredDomain: $persona->domain,
                type: $type,
                severity: $severity,
                title: sprintf('%d %s', $index + 1, $titleSuffix),
                message: 'Seeded for the digest test.',
                data: [],
                createdAt: new \DateTimeImmutable('-'.($index + 1).' hours'),
                resolvedAt: $resolved ? new \DateTimeImmutable('-30 minutes') : null,
            );
            $alert->popEvents();
            $em->persist($alert);
        }
    }

    /**
     * Distinct providers, each with its own identity — so the cap being tested
     * is the display limit and not the identity grouping.
     */
    private function seedReportWithSenders(MonitoredDomain $domain, int $senderCount): void
    {
        $records = [];

        for ($index = 0; $index < $senderCount; ++$index) {
            $ip = sprintf('192.0.2.%d', $index + 1);
            $host = sprintf('mail.sender-%02d.example', $index + 1);
            $this->seedIdentity($ip, $host, sprintf('sender-%02d.example', $index + 1), null, SenderRole::Esp);
            $records[] = ['ip' => $ip, 'count' => 5, 'passes' => true];
        }

        $this->seedReport($domain, $records);
    }
}
