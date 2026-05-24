<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\DmarcRecord;
use App\Entity\DmarcReport;
use App\Entity\MailboxConnection;
use App\Entity\MonitoredDomain;
use App\Entity\ReceivedReportEmail;
use App\Services\CredentialEncryptor;
use App\Tests\Fixtures\Persona;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use App\Value\AuthResult;
use App\Value\Disposition;
use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;
use App\Value\MailboxEncryption;
use App\Value\MailboxType;
use App\Value\Reports\ReportSource;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Coverage for the new `?mailbox=<id>` filter on the reports list page
 * (introduced for TASK-035). The filter joins `dmarc_report.source_envelope_id`
 * to `received_report_email.mailbox_connection_id` so reports without an
 * underlying envelope (legacy, central-inbox) are excluded when the filter
 * is set.
 */
final class ReportsFilterMailboxTest extends WebTestCase
{
    /**
     * @return array{
     *     client: KernelBrowser,
     *     em: EntityManagerInterface,
     *     persona: Persona,
     *     mailboxA: MailboxConnection,
     *     mailboxB: MailboxConnection,
     *     reportFromA: DmarcReport,
     *     reportFromB: DmarcReport,
     *     reportWithoutEnvelope: DmarcReport,
     * }
     */
    private function bootClientWithTwoMailboxesAndReports(): array
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $fixtures = TestFixtures::fromContainer(self::getContainer());

        $persona = $fixtures->persona()
            ->emailPrefix('rf-mb-'.substr(Uuid::uuid7()->toString(), 0, 6))
            ->withDomain('rf-mb.example')
            ->build();
        assert(null !== $persona->domain);

        $encryptor = self::getContainer()->get(CredentialEncryptor::class);
        assert($encryptor instanceof CredentialEncryptor);

        $mailboxA = $this->persistMailbox($em, $encryptor, $persona, 'imap.a.example');
        $mailboxB = $this->persistMailbox($em, $encryptor, $persona, 'imap.b.example');

        $envA = $this->persistEnvelope($em, $mailboxA);
        $envB = $this->persistEnvelope($em, $mailboxB);

        $reportFromA = $this->persistReport($em, $persona->domain, 'google.com', $envA);
        $reportFromB = $this->persistReport($em, $persona->domain, 'yahoo.com', $envB);
        $reportWithoutEnvelope = $this->persistReport($em, $persona->domain, 'microsoft.com', null);

        $em->flush();

        $client->loginUser($persona->user);

        return [
            'client' => $client,
            'em' => $em,
            'persona' => $persona,
            'mailboxA' => $mailboxA,
            'mailboxB' => $mailboxB,
            'reportFromA' => $reportFromA,
            'reportFromB' => $reportFromB,
            'reportWithoutEnvelope' => $reportWithoutEnvelope,
        ];
    }

    #[Test]
    public function reportsPageFiltersByMailbox(): void
    {
        $data = $this->bootClientWithTwoMailboxesAndReports();

        $data['client']->request('GET', '/app/reports?mailbox='.$data['mailboxA']->id->toString());

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        // Reporter orgs are rendered into `<td>...</td>` cells. The filter-bar
        // multiselect repeats every known reporter as an <option>, so the bare
        // string is unreliable — match the table-cell form instead.
        self::assertStringContainsString('<td>google.com</td>', $body);
        self::assertStringNotContainsString('<td>yahoo.com</td>', $body);
        self::assertStringNotContainsString('<td>microsoft.com</td>', $body);
    }

    #[Test]
    public function reportsPageFiltersByDifferentMailboxShowsDifferentRows(): void
    {
        $data = $this->bootClientWithTwoMailboxesAndReports();

        $data['client']->request('GET', '/app/reports?mailbox='.$data['mailboxB']->id->toString());

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringNotContainsString('<td>google.com</td>', $body);
        self::assertStringContainsString('<td>yahoo.com</td>', $body);
        self::assertStringNotContainsString('<td>microsoft.com</td>', $body);
    }

    #[Test]
    public function reportsPageWithoutMailboxFilterShowsAllReportsIncludingNullEnvelope(): void
    {
        $data = $this->bootClientWithTwoMailboxesAndReports();

        $data['client']->request('GET', '/app/reports');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString('<td>google.com</td>', $body);
        self::assertStringContainsString('<td>yahoo.com</td>', $body);
        self::assertStringContainsString('<td>microsoft.com</td>', $body);
    }

    #[Test]
    public function reportsPageIgnoresInvalidMailboxFilterValue(): void
    {
        $data = $this->bootClientWithTwoMailboxesAndReports();

        $data['client']->request('GET', '/app/reports?mailbox=not-a-uuid');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        // Invalid filter → falls back to "no filter" → all rows visible.
        self::assertStringContainsString('<td>google.com</td>', $body);
        self::assertStringContainsString('<td>yahoo.com</td>', $body);
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

    private function persistEnvelope(
        EntityManagerInterface $em,
        MailboxConnection $mailbox,
    ): ReceivedReportEmail {
        $envelope = new ReceivedReportEmail(
            id: Uuid::uuid7(),
            source: ReportSource::ByoMailbox,
            messageId: '<env-'.Uuid::uuid7()->toString().'@test>',
            fromAddress: 'noreply@google.com',
            subject: 'Report',
            receivedAt: new \DateTimeImmutable(),
            ingestedAt: new \DateTimeImmutable(),
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
        string $reporterOrg,
        ?ReceivedReportEmail $envelope,
    ): DmarcReport {
        $report = new DmarcReport(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            reporterOrg: $reporterOrg,
            reporterEmail: 'noreply@'.$reporterOrg,
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

        // Need at least one record so the report shows up in GROUP BY output.
        $em->persist(new DmarcRecord(
            id: Uuid::uuid7(),
            dmarcReport: $report,
            sourceIp: '1.2.3.4',
            count: 10,
            disposition: Disposition::None,
            dkimResult: AuthResult::Pass,
            spfResult: AuthResult::Pass,
            headerFrom: $domain->domain,
        ));

        $em->flush();

        return $report;
    }
}
