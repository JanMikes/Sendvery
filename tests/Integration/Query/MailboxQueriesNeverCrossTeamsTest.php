<?php

declare(strict_types=1);

namespace App\Tests\Integration\Query;

use App\Entity\DmarcReport;
use App\Entity\MailboxConnection;
use App\Entity\MonitoredDomain;
use App\Entity\ReceivedReportEmail;
use App\Entity\Team;
use App\Query\GetDomainIngestionMatrix;
use App\Query\GetMailboxDetail;
use App\Tests\IntegrationTestCase;
use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;
use App\Value\MailboxEncryption;
use App\Value\MailboxType;
use App\Value\Reports\ReportSource;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;

/**
 * Defense in depth for the mailbox read paths, and it is depth rather than the
 * primary fix: the dedupe bug that could create a cross-team envelope link is
 * fixed at its source. These queries are the layer that has to hold anyway.
 *
 * There is no global Doctrine tenant filter in this application —
 * `config/packages/doctrine.php` declares none and `src/Doctrine/` holds only a
 * custom type — so "the SQL is scoped" is not a property anything enforces for
 * us. Every join that reaches a tenant-owned table has to say so itself.
 *
 * Both scenarios below are set up by writing the bad link DIRECTLY rather than
 * by provoking it, because the point is what the query does when handed a row
 * it should not trust — from any cause, including one not invented yet.
 */
final class MailboxQueriesNeverCrossTeamsTest extends IntegrationTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em = $this->getService(EntityManagerInterface::class);
    }

    #[Test]
    public function theIngestionMatrixNeverNamesAnotherTeamsMailServer(): void
    {
        $victim = $this->tenant('victim', 'imap.victim-internal.test', 1993);
        $viewer = $this->tenant('viewer', 'imap.viewer.test', 993);

        // A report on the VIEWER's own domain, provenance-linked to an envelope
        // belonging to the VICTIM's mailbox.
        $this->reportOnDomain($viewer['domain'], $this->envelopeFor($victim['connection']));

        $rows = $this->getService(GetDomainIngestionMatrix::class)->forTeams([$viewer['team']->id->toString()]);

        $viewerRow = null;
        foreach ($rows as $row) {
            if ($row->domainId === $viewer['domain']->id->toString()) {
                $viewerRow = $row;
            }
        }

        self::assertNotNull($viewerRow);
        self::assertNull($viewerRow->mailboxHost, 'The mailbox column renders host and port on /app/domains — a foreign row there is another tenant\'s mail server disclosed to a stranger.');
        self::assertNull($viewerRow->mailboxPort);
        self::assertNull($viewerRow->mailboxId, 'And the connection UUID is the handle every other mailbox surface is addressed by.');
    }

    #[Test]
    public function aMailboxsParsedCountOnlyCountsItsOwnTeamsReports(): void
    {
        $victim = $this->tenant('detail-victim', 'imap.detail-victim.test', 1993);
        $viewer = $this->tenant('detail-viewer', 'imap.detail-viewer.test', 993);

        // A report on the VIEWER's domain hanging off the VICTIM's envelope: the
        // victim's own mailbox page would otherwise count a stranger's report.
        $this->reportOnDomain($viewer['domain'], $this->envelopeFor($victim['connection']));

        $detail = $this->getService(GetMailboxDetail::class)
            ->forMailbox($victim['connection']->id->toString(), [$victim['team']->id->toString()]);

        self::assertNotNull($detail);
        self::assertSame(1, $detail->envelopesTotal, 'The envelope really is this mailbox\'s — that count is correct.');
        self::assertSame(0, $detail->reportsParsed, 'But the report it points at belongs to another team, and a count that includes it is a claim about data this team cannot see.');

        $summary = $this->getService(GetMailboxDetail::class)->summaryForMailboxes([$victim['connection']->id->toString()]);
        self::assertArrayHasKey($victim['connection']->id->toString(), $summary);
        self::assertSame(0, $summary[$victim['connection']->id->toString()]->reports30d, 'The batched list-page count reads the same join and must agree.');
    }

    #[Test]
    public function anEmailCarryingTwoReportsIsStillOneDelivery(): void
    {
        // Not a tenant question, but it is a defect in the same join, found and
        // fixed while adding the predicate above: the activity query fans out
        // one row per report, so `COUNT(*)` counted an envelope once per report
        // it carried. A reporter bundling two domains' reports in one mail made
        // the list page claim two emails had arrived where one had.
        $tenant = $this->tenant('fan-out', 'imap.fan-out.test', 993);
        $envelope = $this->envelopeFor($tenant['connection']);

        $this->reportOnDomain($tenant['domain'], $envelope);
        $this->reportOnDomain($tenant['domain'], $envelope);

        $summary = $this->getService(GetMailboxDetail::class)->summaryForMailboxes([$tenant['connection']->id->toString()]);

        self::assertSame(1, $summary[$tenant['connection']->id->toString()]->envelopes30d, 'One email arrived, whatever it happened to contain.');
        self::assertSame(2, $summary[$tenant['connection']->id->toString()]->reports30d, 'Two reports came out of it, and that count is the one that should be two.');
    }

    private function envelopeFor(MailboxConnection $connection): ReceivedReportEmail
    {
        $envelope = new ReceivedReportEmail(
            id: Uuid::uuid7(),
            source: ReportSource::ByoMailbox,
            messageId: '<cross-team-'.Uuid::uuid7()->toString().'@example.com>',
            fromAddress: 'noreply-dmarc-support@google.com',
            subject: 'Report Domain: example.com',
            receivedAt: new \DateTimeImmutable(),
            ingestedAt: new \DateTimeImmutable(),
            sizeBytes: 4,
            rawEml: 'body',
            mailboxConnection: $connection,
        );
        $this->em->persist($envelope);
        $this->em->flush();

        return $envelope;
    }

    private function reportOnDomain(MonitoredDomain $domain, ReceivedReportEmail $envelope): void
    {
        $report = new DmarcReport(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            reporterOrg: 'google.com',
            reporterEmail: 'noreply-dmarc-support@google.com',
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
        $this->em->persist($report);
        $this->em->flush();
    }

    /** @return array{team: Team, domain: MonitoredDomain, connection: MailboxConnection} */
    private function tenant(string $label, string $host, int $port): array
    {
        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Tenant '.$label,
            slug: $label.'-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $team->popEvents();
        $this->em->persist($team);

        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: $label.'-'.Uuid::uuid7()->toString().'.example',
            createdAt: new \DateTimeImmutable(),
        );
        $domain->popEvents();
        $this->em->persist($domain);

        $connection = new MailboxConnection(
            id: Uuid::uuid7(),
            team: $team,
            type: MailboxType::ImapUser,
            host: $host,
            port: $port,
            encryptedUsername: 'enc-user',
            encryptedPassword: 'enc-pass',
            encryption: MailboxEncryption::Ssl,
            createdAt: new \DateTimeImmutable(),
            monitoredDomain: $domain,
        );
        $connection->popEvents();
        $this->em->persist($connection);
        $this->em->flush();

        return ['team' => $team, 'domain' => $domain, 'connection' => $connection];
    }
}
