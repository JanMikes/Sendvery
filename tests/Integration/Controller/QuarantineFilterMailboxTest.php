<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\MailboxConnection;
use App\Entity\MonitoredDomain;
use App\Entity\QuarantinedDmarcReport;
use App\Entity\ReceivedReportEmail;
use App\Services\CredentialEncryptor;
use App\Tests\Fixtures\Persona;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use App\Value\MailboxEncryption;
use App\Value\MailboxType;
use App\Value\Reports\QuarantineReason;
use App\Value\Reports\ReportSource;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Coverage for the new `?mailbox=<id>` filter on the quarantine list page.
 * Quarantine rows are matched on `received_report_email.mailbox_connection_id`
 * so a filter that names mailbox A only surfaces rows whose envelope came from
 * mailbox A — never the team's other mailboxes nor central-inbox NULL-mailbox
 * rows.
 */
final class QuarantineFilterMailboxTest extends WebTestCase
{
    /**
     * @return array{
     *     client: KernelBrowser,
     *     em: EntityManagerInterface,
     *     persona: Persona,
     *     mailboxA: MailboxConnection,
     *     mailboxB: MailboxConnection,
     *     domainA: string,
     *     domainB: string,
     * }
     */
    private function bootClientWithTwoMailboxesAndQuarantine(): array
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $fixtures = TestFixtures::fromContainer(self::getContainer());

        $persona = $fixtures->persona()
            ->emailPrefix('qf-mb-'.substr(Uuid::uuid7()->toString(), 0, 6))
            ->withoutDomain()
            ->build();

        $encryptor = self::getContainer()->get(CredentialEncryptor::class);
        assert($encryptor instanceof CredentialEncryptor);

        $mailboxA = $this->persistMailbox($em, $encryptor, $persona, 'imap.qa.example');
        $mailboxB = $this->persistMailbox($em, $encryptor, $persona, 'imap.qb.example');

        $domainAName = 'qa-'.substr(Uuid::uuid7()->toString(), 0, 8).'.test';
        $domainBName = 'qb-'.substr(Uuid::uuid7()->toString(), 0, 8).'.test';
        $this->persistDomain($em, $persona, $domainAName);
        $this->persistDomain($em, $persona, $domainBName);

        $this->persistQuarantine($em, $mailboxA, $domainAName);
        $this->persistQuarantine($em, $mailboxB, $domainBName);

        $em->flush();

        $client->loginUser($persona->user);

        return [
            'client' => $client,
            'em' => $em,
            'persona' => $persona,
            'mailboxA' => $mailboxA,
            'mailboxB' => $mailboxB,
            'domainA' => $domainAName,
            'domainB' => $domainBName,
        ];
    }

    #[Test]
    public function quarantinePageFiltersByMailbox(): void
    {
        $data = $this->bootClientWithTwoMailboxesAndQuarantine();

        $data['client']->request('GET', '/app/quarantine?mailbox='.$data['mailboxA']->id->toString());

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString($data['domainA'], $body);
        self::assertStringNotContainsString($data['domainB'], $body);
    }

    #[Test]
    public function quarantinePageFiltersByDifferentMailboxShowsDifferentRows(): void
    {
        $data = $this->bootClientWithTwoMailboxesAndQuarantine();

        $data['client']->request('GET', '/app/quarantine?mailbox='.$data['mailboxB']->id->toString());

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringNotContainsString($data['domainA'], $body);
        self::assertStringContainsString($data['domainB'], $body);
    }

    #[Test]
    public function quarantinePageWithoutMailboxFilterShowsBothMailboxes(): void
    {
        $data = $this->bootClientWithTwoMailboxesAndQuarantine();

        $data['client']->request('GET', '/app/quarantine');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString($data['domainA'], $body);
        self::assertStringContainsString($data['domainB'], $body);
    }

    #[Test]
    public function quarantinePageIgnoresInvalidMailboxFilterValue(): void
    {
        $data = $this->bootClientWithTwoMailboxesAndQuarantine();

        $data['client']->request('GET', '/app/quarantine?mailbox=not-a-uuid');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        // Invalid filter → falls back to no filter → both rows visible.
        self::assertStringContainsString($data['domainA'], $body);
        self::assertStringContainsString($data['domainB'], $body);
    }

    #[Test]
    public function reasonChipsPreserveMailboxFilterInTheirHrefs(): void
    {
        // Regression guard for the must-fix in TASK-035 review: clicking a
        // reason chip from /app/quarantine?mailbox=X must NOT silently drop
        // the mailbox filter. Every chip's path() call must carry it forward.
        $data = $this->bootClientWithTwoMailboxesAndQuarantine();
        $mailboxA = $data['mailboxA']->id->toString();

        $data['client']->request('GET', '/app/quarantine?mailbox='.$mailboxA);

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString('/app/quarantine?reason=unknown_domain&amp;mailbox='.$mailboxA, $body);
        self::assertStringContainsString('/app/quarantine?reason=unverified_domain&amp;mailbox='.$mailboxA, $body);
        self::assertStringContainsString('/app/quarantine?reason=plan_overage&amp;mailbox='.$mailboxA, $body);
        self::assertStringContainsString('/app/quarantine?mailbox='.$mailboxA.'"', $body, 'All chip must preserve mailbox filter');
    }

    #[Test]
    public function reasonChipCountsHonourTheMailboxFilter(): void
    {
        // When ?mailbox= is active, the (N) numbers on the chips MUST reflect
        // the mailbox-scoped counts, not team-wide totals — otherwise a user
        // sees "All (47)" while the table shows 3 rows and is confused.
        $data = $this->bootClientWithTwoMailboxesAndQuarantine();
        $mailboxA = $data['mailboxA']->id->toString();

        // bootClientWithTwoMailboxesAndQuarantine seeds 1 row per mailbox →
        // mailbox A has exactly 1 row. The "All ({N})" chip should read 1, not 2.
        $data['client']->request('GET', '/app/quarantine?mailbox='.$mailboxA);

        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString('All (1)', $body, 'All chip must show the mailbox-scoped total.');
    }

    #[Test]
    public function quarantinePagePreservesMailboxFilterInPaginationLinks(): void
    {
        $data = $this->bootClientWithTwoMailboxesAndQuarantine();
        $em = $data['em'];

        // Push past one page so the Next link renders. PAGE_SIZE is 50 — seed
        // 51 quarantine rows tied to mailbox A.
        for ($i = 0; $i < 51; ++$i) {
            $this->persistQuarantine($em, $data['mailboxA'], $data['domainA']);
        }
        $em->flush();

        $crawler = $data['client']->request('GET', '/app/quarantine?mailbox='.$data['mailboxA']->id->toString());

        self::assertResponseIsSuccessful();
        // Look for Next link with mailbox param preserved.
        $nextLink = $crawler->filter('a.join-item:contains("Next")');
        self::assertGreaterThan(0, $nextLink->count(), 'Expected a Next pagination link');
        $href = (string) $nextLink->first()->attr('href');
        self::assertStringContainsString('mailbox='.$data['mailboxA']->id->toString(), $href);
    }

    private function persistMailbox(
        EntityManagerInterface $em,
        CredentialEncryptor $encryptor,
        Persona $persona,
        string $host,
    ): MailboxConnection {
        $mailbox = new MailboxConnection(
            id: Uuid::uuid7(),
            team: $persona->team,
            type: MailboxType::ImapUser,
            host: $host,
            port: 993,
            encryptedUsername: $encryptor->encrypt('u'),
            encryptedPassword: $encryptor->encrypt('p'),
            encryption: MailboxEncryption::Ssl,
            createdAt: new \DateTimeImmutable(),
        );
        $mailbox->popEvents();
        $em->persist($mailbox);
        $em->flush();

        return $mailbox;
    }

    private function persistDomain(
        EntityManagerInterface $em,
        Persona $persona,
        string $name,
    ): MonitoredDomain {
        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $persona->team,
            domain: $name,
            createdAt: new \DateTimeImmutable(),
        );
        $domain->popEvents();
        $em->persist($domain);
        $em->flush();

        return $domain;
    }

    private function persistQuarantine(
        EntityManagerInterface $em,
        MailboxConnection $mailbox,
        string $domainName,
    ): QuarantinedDmarcReport {
        $envelope = new ReceivedReportEmail(
            id: Uuid::uuid7(),
            source: ReportSource::ByoMailbox,
            messageId: '<env-'.Uuid::uuid7()->toString().'@test>',
            fromAddress: 'noreply@google.com',
            subject: 'Quarantine fixture',
            receivedAt: new \DateTimeImmutable(),
            ingestedAt: new \DateTimeImmutable(),
            sizeBytes: 1024,
            rawEml: 'x',
            mailboxConnection: $mailbox,
        );
        $em->persist($envelope);

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
