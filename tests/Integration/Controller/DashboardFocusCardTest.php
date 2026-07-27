<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\Alert;
use App\Entity\DomainHealthSnapshot;
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
 * The `/app` focus card: one card carrying the health headline, the drill-down
 * chips for every "something is wrong" signal, and the single next step.
 *
 * Replaces the coverage of the standalone "N things need your attention today"
 * line, which was one of three separate summaries stacked above each other. Its
 * chips survived the merge — its self-contradicting count did not, which is what
 * the headline-agreement test below pins down.
 */
final class DashboardFocusCardTest extends WebTestCase
{
    #[Test]
    public function aFullyHealthyTeamGetsAnAllClearHeadlineAndNoAttentionChips(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $em = $this->getService(EntityManagerInterface::class);

        $persona = $fixtures->onboardedOwner();
        $this->makeEveryDomainHealthy($em, $persona->team);

        $client->loginUser($persona->user);
        $crawler = $client->request('GET', '/app');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('All domains healthy', $body);
        self::assertCount(
            0,
            $crawler->filter('a.hover\\:underline.text-error, a.hover\\:underline.text-warning'),
            'A team with nothing wrong must render no attention chips at all.',
        );
    }

    #[Test]
    public function everySignalRendersAsADeepLinkedChipInSeverityOrder(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $em = $this->getService(EntityManagerInterface::class);

        // The default persona ships one unverified domain. Layering on a
        // verified-but-incomplete domain, a critical alert and a quarantined
        // report gives us one of each signal.
        $persona = $fixtures->onboardedOwner();
        $attentionDomain = $fixtures->addExtraDomain($persona->team, 'focus-attention-'.substr(uniqid('', true), -6));
        $attentionDomain->dmarcVerifiedAt = new \DateTimeImmutable('-3 days');

        $this->persistAlert($em, $persona->team, AlertSeverity::Critical);
        $this->persistQuarantined($em, $attentionDomain->domain);
        $em->flush();
        $em->clear();

        $client->loginUser($persona->user);
        $crawler = $client->request('GET', '/app');

        self::assertResponseIsSuccessful();

        // Constrained to the chip anchors via their tone classes: a body-wide
        // substring search would mis-match the sidebar badges' aria-labels,
        // which contain the same phrases.
        $chips = $crawler->filter('a.hover\\:underline.text-error, a.hover\\:underline.text-warning');
        self::assertCount(4, $chips, 'One chip per signal: critical alerts, attention, unverified, quarantine.');

        self::assertStringContainsString('critical alert', $chips->eq(0)->text());
        self::assertSame('/app/alerts?severity=critical&isRead=0', $chips->eq(0)->attr('href'));

        self::assertStringContainsString('needs attention', $chips->eq(1)->text());
        self::assertSame('/app/domains?status=attention', $chips->eq(1)->attr('href'));

        self::assertStringContainsString('unverified domain', $chips->eq(2)->text());
        self::assertSame('/app/domains?status=unverified', $chips->eq(2)->attr('href'));

        self::assertStringContainsString('in quarantine', $chips->eq(3)->text());
        self::assertSame('/app/quarantine', $chips->eq(3)->attr('href'));
    }

    #[Test]
    public function theAttentionChipAgreesWithTheHeadlineAboveItWithoutEchoingIt(): void
    {
        // The reported bug: the hero said "3 domains need attention" and then,
        // one line lower, "1 thing needs your attention today: 1 unverified
        // domain". Both numbers are now classified from the same domain rows, so
        // the page can no longer state two different attention counts.
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $em = $this->getService(EntityManagerInterface::class);

        $persona = $fixtures->onboardedOwner();
        $this->makeEveryDomainHealthy($em, $persona->team);

        foreach (['focus-count-a', 'focus-count-b'] as $name) {
            $domain = $fixtures->addExtraDomain($persona->team, $name.'-'.substr(uniqid('', true), -6));
            $domain->dmarcVerifiedAt = new \DateTimeImmutable('-3 days');
        }
        $em->flush();
        $em->clear();

        $client->loginUser($persona->user);
        $client->request('GET', '/app');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();

        self::assertStringContainsString(
            '2 domains need attention',
            $body,
            'The headline states the count.',
        );
        self::assertStringContainsString(
            '2 need attention',
            $body,
            'And the chip links to the matching filter with the same number.',
        );
        self::assertSame(
            1,
            substr_count($body, '2 domains need attention'),
            'The chip must not echo the headline sentence verbatim — two identical summaries stacked on top of each other is the clutter this card replaced.',
        );
        foreach (['1 need attention', '3 need attention', '1 domain needs attention'] as $rivalCount) {
            self::assertStringNotContainsString(
                $rivalCount,
                $body,
                'No surface on the page may state a different attention count from the headline.',
            );
        }
    }

    /**
     * All four protocols in place for every domain on the team. MX has no
     * verified-at column, so it is proved with a health snapshot the same way
     * the classifier reads it.
     */
    private function makeEveryDomainHealthy(EntityManagerInterface $em, Team $team): void
    {
        /** @var list<MonitoredDomain> $domains */
        $domains = $em->createQuery('SELECT d FROM App\Entity\MonitoredDomain d WHERE d.team = :team')
            ->setParameter('team', $team)
            ->getResult();

        foreach ($domains as $domain) {
            $em->persist(new DomainHealthSnapshot(
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
        }
        $em->flush();

        $em->createQuery(
            'UPDATE App\Entity\MonitoredDomain d
             SET d.dmarcVerifiedAt = :verifiedAt, d.spfVerifiedAt = :verifiedAt, d.dkimVerifiedAt = :verifiedAt
             WHERE d.team = :team',
        )->execute([
            'team' => $team,
            'verifiedAt' => new \DateTimeImmutable('-2 days'),
        ]);

        // Deliberately NOT clearing the entity manager: callers keep using the
        // persona's managed Team to add further domains. The bulk UPDATE bypasses
        // the identity map, and every read the controller makes goes to the DB,
        // so the stale in-memory copies are harmless.
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

    private function persistQuarantined(EntityManagerInterface $em, string $domainName): void
    {
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

        $em->persist(new QuarantinedDmarcReport(
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
        ));
    }
}
