<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\Alert;
use App\Entity\DmarcRecord;
use App\Entity\DmarcReport;
use App\Entity\MonitoredDomain;
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
    public function newSendersAreGroupedPerDomainWithATotalAndACappedChipList(): void
    {
        $persona = $this->onboardedPersona();
        assert(null !== $persona->domain);
        $this->seedReportWithSenders($persona->domain, senderCount: 11);

        $html = $this->renderDigest($persona);

        self::assertStringContainsString('New senders discovered', $html);
        self::assertStringContainsString('· 11 new', $html);
        self::assertStringContainsString('+3 more', $html);
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
        $em = $this->getService(EntityManagerInterface::class);
        $em->flush();

        $handler = $this->getService(SendWeeklyDigestHandler::class);
        $handler(new SendWeeklyDigest(teamId: $persona->team->id));

        $messages = self::getMailerMessages();
        self::assertNotSame([], $messages, 'Expected the digest to be sent to the team owner.');
        $message = $messages[0];
        self::assertInstanceOf(Email::class, $message);

        return (string) $message->getHtmlBody();
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

    private function seedReportWithSenders(MonitoredDomain $domain, int $senderCount): void
    {
        $em = $this->getService(EntityManagerInterface::class);

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
        $em->persist($report);

        for ($index = 0; $index < $senderCount; ++$index) {
            $em->persist(new DmarcRecord(
                id: Uuid::uuid7(),
                dmarcReport: $report,
                sourceIp: sprintf('192.0.2.%d', $index + 1),
                count: 5,
                disposition: Disposition::None,
                dkimResult: AuthResult::Pass,
                spfResult: AuthResult::Pass,
                headerFrom: $domain->domain,
                resolvedOrg: sprintf('sender-%02d.example', $index + 1),
            ));
        }
    }
}
