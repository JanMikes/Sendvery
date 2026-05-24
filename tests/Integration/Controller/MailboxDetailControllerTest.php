<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\DmarcReport;
use App\Entity\MailboxConnection;
use App\Entity\MonitoredDomain;
use App\Entity\QuarantinedDmarcReport;
use App\Entity\ReceivedReportEmail;
use App\Services\CredentialEncryptor;
use App\Tests\Fixtures\Persona;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;
use App\Value\MailboxEncryption;
use App\Value\MailboxType;
use App\Value\Reports\QuarantineReason;
use App\Value\Reports\ReportSource;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Coverage for TASK-035 — the new per-mailbox detail page at
 * /app/mailboxes/{id}, plus the stat-row deep-links into the filtered
 * reports / quarantine list views.
 */
final class MailboxDetailControllerTest extends WebTestCase
{
    /**
     * @return array{client: KernelBrowser, em: EntityManagerInterface, persona: Persona, mailbox: MailboxConnection}
     */
    private function bootClientWithMailbox(): array
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $fixtures = TestFixtures::fromContainer(self::getContainer());

        $persona = $fixtures->persona()
            ->emailPrefix('mb-detail-'.substr(Uuid::uuid7()->toString(), 0, 6))
            ->withDomain('mb-detail.example')
            ->build();

        $encryptor = self::getContainer()->get(CredentialEncryptor::class);
        assert($encryptor instanceof CredentialEncryptor);

        $mailbox = new MailboxConnection(
            id: Uuid::uuid7(),
            team: $persona->team,
            type: MailboxType::ImapUser,
            host: 'imap.detail.example',
            port: 993,
            encryptedUsername: $encryptor->encrypt('user@detail.example'),
            encryptedPassword: $encryptor->encrypt('s3cret'),
            encryption: MailboxEncryption::Ssl,
            createdAt: new \DateTimeImmutable(),
            lastPolledAt: new \DateTimeImmutable('-30 minutes'),
        );
        $mailbox->popEvents();
        $em->persist($mailbox);
        $em->flush();

        $client->loginUser($persona->user);

        return [
            'client' => $client,
            'em' => $em,
            'persona' => $persona,
            'mailbox' => $mailbox,
        ];
    }

    #[Test]
    public function pageReturns200ForKnownMailbox(): void
    {
        $data = $this->bootClientWithMailbox();

        $data['client']->request('GET', '/app/mailboxes/'.$data['mailbox']->id->toString());

        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function pageReturns404ForUnknownMailbox(): void
    {
        $data = $this->bootClientWithMailbox();

        $data['client']->request('GET', '/app/mailboxes/'.Uuid::uuid7()->toString());

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function pageReturns404ForCrossTenantMailbox(): void
    {
        $data = $this->bootClientWithMailbox();
        $em = $data['em'];
        $fixtures = TestFixtures::fromContainer(self::getContainer());

        $intruder = $fixtures->persona()
            ->emailPrefix('intruder-'.substr(Uuid::uuid7()->toString(), 0, 6))
            ->withDomain('intruder.example')
            ->build();
        $encryptor = self::getContainer()->get(CredentialEncryptor::class);
        assert($encryptor instanceof CredentialEncryptor);
        $foreignMailbox = new MailboxConnection(
            id: Uuid::uuid7(),
            team: $intruder->team,
            type: MailboxType::ImapUser,
            host: 'imap.foreign.example',
            port: 993,
            encryptedUsername: $encryptor->encrypt('a'),
            encryptedPassword: $encryptor->encrypt('b'),
            encryption: MailboxEncryption::Ssl,
            createdAt: new \DateTimeImmutable(),
        );
        $foreignMailbox->popEvents();
        $em->persist($foreignMailbox);
        $em->flush();

        $data['client']->request('GET', '/app/mailboxes/'.$foreignMailbox->id->toString());

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function pageShowsConnectionDetails(): void
    {
        $data = $this->bootClientWithMailbox();

        $data['client']->request('GET', '/app/mailboxes/'.$data['mailbox']->id->toString());

        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString('imap.detail.example', $body);
        self::assertStringContainsString('993', $body);
        self::assertStringContainsString('ssl', $body);
        self::assertStringContainsString('imap_user', $body);
        self::assertStringContainsString('Connection details', $body);
    }

    #[Test]
    public function pageShowsStatRowCounts(): void
    {
        $data = $this->bootClientWithMailbox();
        $persona = $data['persona'];
        $em = $data['em'];
        assert(null !== $persona->domain);

        // Persist one parsed envelope and one quarantined envelope so the
        // stat row reports 2 envelopes (1 report, 1 quarantined).
        $env1 = $this->persistEnvelope($em, $data['mailbox'], new \DateTimeImmutable('-1 day'));
        $this->persistReport($em, $persona->domain, $env1);

        $env2 = $this->persistEnvelope($em, $data['mailbox'], new \DateTimeImmutable('-2 days'));
        $this->persistQuarantine($em, $env2, $persona->domain->domain);

        $crawler = $data['client']->request('GET', '/app/mailboxes/'.$data['mailbox']->id->toString());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Envelopes pulled');
        self::assertSelectorTextContains('body', 'Reports parsed');
        self::assertSelectorTextContains('body', 'Envelopes quarantined');

        // The card values must reflect the seeded counts. The StatCard
        // renders the value inside a `text-2xl font-bold` span.
        $valueNodes = $crawler->filter('.card .text-2xl.font-bold');
        $values = [];
        foreach ($valueNodes as $node) {
            $values[] = trim($node->textContent);
        }

        // One parsed report, one quarantined envelope, total 2 envelopes in 30d.
        self::assertContains('2', $values);
        self::assertContains('1', $values);
    }

    #[Test]
    public function statRowCountsAreClickableToFilteredLists(): void
    {
        $data = $this->bootClientWithMailbox();

        $crawler = $data['client']->request('GET', '/app/mailboxes/'.$data['mailbox']->id->toString());

        self::assertResponseIsSuccessful();
        $expectedMailboxId = $data['mailbox']->id->toString();

        // "Reports parsed" card wrapped in an anchor to filtered reports list.
        $reportsAnchors = $crawler->filter('a[href*="/app/reports"][href*="mailbox="]');
        self::assertGreaterThan(0, $reportsAnchors->count());
        $reportsHref = (string) $reportsAnchors->first()->attr('href');
        self::assertStringContainsString('mailbox='.$expectedMailboxId, $reportsHref);

        // "Envelopes quarantined" card wrapped in an anchor to filtered quarantine list.
        $quarantineAnchors = $crawler->filter('a[href*="/app/quarantine"][href*="mailbox="]');
        self::assertGreaterThan(0, $quarantineAnchors->count());
        $quarantineHref = (string) $quarantineAnchors->first()->attr('href');
        self::assertStringContainsString('mailbox='.$expectedMailboxId, $quarantineHref);
    }

    #[Test]
    public function pageShowsRecentEnvelopesTable(): void
    {
        $data = $this->bootClientWithMailbox();
        $em = $data['em'];
        $persona = $data['persona'];
        assert(null !== $persona->domain);

        $env = $this->persistEnvelope($em, $data['mailbox'], new \DateTimeImmutable('-1 hour'));
        $this->persistReport($em, $persona->domain, $env);

        $crawler = $data['client']->request('GET', '/app/mailboxes/'.$data['mailbox']->id->toString());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Recent envelopes');
        // Subject from envelope fixture appears.
        self::assertStringContainsString('DMARC report', (string) $data['client']->getResponse()->getContent());
        // Table has a "Status" header.
        self::assertGreaterThan(0, $crawler->filter('th:contains("Status")')->count());
    }

    #[Test]
    public function recentEnvelopeStatusLinksToReportOrQuarantine(): void
    {
        $data = $this->bootClientWithMailbox();
        $em = $data['em'];
        $persona = $data['persona'];
        assert(null !== $persona->domain);

        $envParsed = $this->persistEnvelope($em, $data['mailbox'], new \DateTimeImmutable('-1 hour'));
        $report = $this->persistReport($em, $persona->domain, $envParsed);

        $envQuarantined = $this->persistEnvelope($em, $data['mailbox'], new \DateTimeImmutable('-2 hours'));
        $quarantine = $this->persistQuarantine($em, $envQuarantined, $persona->domain->domain);

        $crawler = $data['client']->request('GET', '/app/mailboxes/'.$data['mailbox']->id->toString());

        self::assertResponseIsSuccessful();

        // Parsed envelope links to the report detail.
        $parsedLinks = $crawler->filter('a[href="/app/reports/'.$report->id->toString().'"]');
        self::assertGreaterThan(0, $parsedLinks->count());

        // Quarantined envelope links to the quarantine detail.
        $quarantineLinks = $crawler->filter('a[href="/app/quarantine/'.$quarantine->id->toString().'"]');
        self::assertGreaterThan(0, $quarantineLinks->count());
    }

    #[Test]
    public function pageShowsEmptyStateWhenNoEnvelopes(): void
    {
        $data = $this->bootClientWithMailbox();

        $data['client']->request('GET', '/app/mailboxes/'.$data['mailbox']->id->toString());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'No envelopes received yet');
    }

    #[Test]
    public function reTestButtonIsPresentOnDetailPage(): void
    {
        $data = $this->bootClientWithMailbox();

        $crawler = $data['client']->request('GET', '/app/mailboxes/'.$data['mailbox']->id->toString());

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(
            0,
            $crawler->filter('form[action="/app/mailboxes/'.$data['mailbox']->id->toString().'/test"]')->count(),
        );
    }

    private function persistEnvelope(
        EntityManagerInterface $em,
        MailboxConnection $mailbox,
        \DateTimeImmutable $receivedAt,
    ): ReceivedReportEmail {
        $envelope = new ReceivedReportEmail(
            id: Uuid::uuid7(),
            source: ReportSource::ByoMailbox,
            messageId: '<env-'.Uuid::uuid7()->toString().'@test>',
            fromAddress: 'noreply@google.com',
            subject: 'DMARC report fixture',
            receivedAt: $receivedAt,
            ingestedAt: $receivedAt,
            sizeBytes: 1024,
            rawEml: 'x',
            mailboxConnection: $mailbox,
        );
        $em->persist($envelope);
        $em->flush();

        return $envelope;
    }

    private function persistReport(
        EntityManagerInterface $em,
        MonitoredDomain $domain,
        ReceivedReportEmail $envelope,
    ): DmarcReport {
        $report = new DmarcReport(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            reporterOrg: 'google.com',
            reporterEmail: 'noreply@google.com',
            externalReportId: 'ext-'.Uuid::uuid7()->toString(),
            dateRangeBegin: new \DateTimeImmutable('-1 day'),
            dateRangeEnd: new \DateTimeImmutable(),
            policyDomain: $domain->domain,
            policyAdkim: DmarcAlignment::Relaxed,
            policyAspf: DmarcAlignment::Relaxed,
            policyP: DmarcPolicy::None,
            policySp: null,
            policyPct: 100,
            rawXml: '<feedback/>',
            processedAt: new \DateTimeImmutable(),
            sourceEnvelope: $envelope,
        );
        $report->popEvents();
        $em->persist($report);
        $em->flush();

        return $report;
    }

    private function persistQuarantine(
        EntityManagerInterface $em,
        ReceivedReportEmail $envelope,
        string $domainName,
    ): QuarantinedDmarcReport {
        $compressed = gzencode('<feedback/>');
        assert(false !== $compressed);

        $quarantine = new QuarantinedDmarcReport(
            id: Uuid::uuid7(),
            receivedEmail: $envelope,
            domainName: $domainName,
            externalReportId: 'ext-'.Uuid::uuid7()->toString(),
            reporterOrg: 'google.com',
            reporterEmail: 'noreply@google.com',
            dateRangeBegin: new \DateTimeImmutable('-1 day'),
            dateRangeEnd: new \DateTimeImmutable(),
            quarantinedAt: new \DateTimeImmutable(),
            expiresAt: new \DateTimeImmutable('+60 days'),
            reason: QuarantineReason::UnverifiedDomain,
            reportXmlGz: $compressed,
        );
        $em->persist($quarantine);
        $em->flush();

        return $quarantine;
    }
}
