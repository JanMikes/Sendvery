<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\Alert;
use App\Entity\MonitoredDomain;
use App\Entity\QuarantinedDmarcReport;
use App\Entity\ReceivedReportEmail;
use App\Entity\Team;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use App\Value\AlertSeverity;
use App\Value\AlertType;
use App\Value\Reports\QuarantineReason;
use App\Value\Reports\ReportSource;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;

/**
 * Integration coverage for TASK-062: the `/app` hero "things need your
 * attention today" line.
 *
 * Verifies the rendered overview HTML:
 * - emits the headline + the right number of `<a>` items in severity order
 *   when at least one signal is non-zero
 * - is completely absent when every signal is zero (we lean on the existing
 *   healthSummary headline to carry the "all clear" mood)
 */
final class AttentionSummaryLineTest extends WebTestCase
{
    #[Test]
    public function lineIsAbsentForAllZeroTeam(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $em = $this->getService(EntityManagerInterface::class);

        // Default persona ships an unverified domain; flip it verified so
        // unverified_count = 0 AND we keep `dashboard_overview` from redirecting
        // to onboarding (which it does for personas with zero domains).
        $persona = $fixtures->onboardedOwner();
        $em->createQuery(
            'UPDATE App\\Entity\\MonitoredDomain d SET d.dmarcVerifiedAt = :now WHERE d.team = :team',
        )->execute([
            'team' => $persona->team,
            'now' => new \DateTimeImmutable(),
        ]);
        $em->flush();
        $em->clear();

        $client->loginUser($persona->user);
        $client->request('GET', '/app');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('things need your attention today', $body);
        self::assertStringNotContainsString('thing needs your attention today', $body);
    }

    #[Test]
    public function lineSurfacesAllThreeSignalsInSeverityOrder(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $em = $this->getService(EntityManagerInterface::class);

        // The default persona has an unverified domain by design; layering on
        // a critical alert + a quarantined report gives us all three signals.
        $persona = $fixtures->onboardedOwner();

        $this->persistAlert($em, $persona->team, AlertSeverity::Critical);
        $this->persistQuarantined($em, $persona->team->id->toString());

        // Force the domain unverified so the unverified-count > 0 branch fires.
        $em->createQuery(
            'UPDATE App\\Entity\\MonitoredDomain d SET d.dmarcVerifiedAt = NULL WHERE d.team = :team',
        )->execute(['team' => $persona->team]);

        $em->flush();
        $em->clear();

        $client->loginUser($persona->user);

        $crawler = $client->request('GET', '/app');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('things need your attention today', $body);

        // Constrain assertions to the actual attention-summary anchors via
        // the `hover:underline` text-tone classes the component sets. Body-
        // wide substring search would mis-match the sidebar badges' aria-
        // labels (which also contain "in quarantine" / "unverified" etc).
        $anchors = $crawler->filter('a.hover\\:underline.text-error, a.hover\\:underline.text-warning');
        self::assertCount(3, $anchors, 'attention line must render exactly three anchors');

        // Severity-ordered: critical alerts → unverified domains → quarantine.
        self::assertStringContainsString('critical alert', $anchors->eq(0)->text());
        self::assertSame('text-error', trim(str_replace('hover:underline', '', (string) $anchors->eq(0)->attr('class'))));
        self::assertSame('/app/alerts?severity=critical&isRead=0', $anchors->eq(0)->attr('href'));

        self::assertStringContainsString('unverified domain', $anchors->eq(1)->text());
        self::assertSame('/app/domains?status=unverified', $anchors->eq(1)->attr('href'));

        self::assertStringContainsString('in quarantine', $anchors->eq(2)->text());
        self::assertSame('/app/quarantine', $anchors->eq(2)->attr('href'));
    }

    private function persistAlert(EntityManagerInterface $em, Team $team, AlertSeverity $severity): void
    {
        $alert = new Alert(
            id: Uuid::uuid7(),
            team: $team,
            monitoredDomain: null,
            type: AlertType::FailureSpike,
            severity: $severity,
            title: 'Test alert',
            message: 'Test message',
            data: [],
            createdAt: new \DateTimeImmutable(),
            isRead: false,
            snoozedUntil: null,
        );
        $alert->popEvents();
        $em->persist($alert);
    }

    /**
     * Persists a quarantined-report row owned by the given team — uses the
     * UnknownDomain reason + a mailbox-less envelope so the visibility WHERE
     * matches a domain owned by the team (we look up the team's first domain).
     */
    private function persistQuarantined(EntityManagerInterface $em, string $teamId): void
    {
        $domain = $em->createQuery(
            'SELECT d FROM App\\Entity\\MonitoredDomain d WHERE d.team = :teamId',
        )->setParameter('teamId', $teamId)->getResult();
        assert(is_array($domain));
        $domainName = $domain[0] instanceof MonitoredDomain ? $domain[0]->domain : 'fallback.example';

        $envelope = new ReceivedReportEmail(
            id: Uuid::uuid7(),
            source: ReportSource::CentralInbox,
            messageId: '<env-'.Uuid::uuid7()->toString().'@test>',
            fromAddress: 'noreply-dmarc@google.com',
            subject: 'Report Domain: '.$domainName,
            receivedAt: new \DateTimeImmutable('-2 hours'),
            ingestedAt: new \DateTimeImmutable('-2 hours'),
            sizeBytes: 100,
            rawEml: 'fake',
            mailboxConnection: null,
        );
        $em->persist($envelope);

        $xml = '<feedback></feedback>';
        $compressed = gzencode($xml);
        assert(false !== $compressed);

        $quarantine = new QuarantinedDmarcReport(
            id: Uuid::uuid7(),
            receivedEmail: $envelope,
            domainName: $domainName,
            externalReportId: 'ext-'.Uuid::uuid7()->toString(),
            reporterOrg: 'google.com',
            reporterEmail: 'noreply-dmarc-support@google.com',
            dateRangeBegin: new \DateTimeImmutable('@1700000000'),
            dateRangeEnd: new \DateTimeImmutable('@1700086400'),
            quarantinedAt: new \DateTimeImmutable('-1 hour'),
            expiresAt: new \DateTimeImmutable('+60 days'),
            reason: QuarantineReason::UnknownDomain,
            reportXmlGz: $compressed,
        );
        $em->persist($quarantine);
    }
}
