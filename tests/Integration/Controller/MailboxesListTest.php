<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\DmarcReport;
use App\Entity\MailboxConnection;
use App\Entity\MonitoredDomain;
use App\Entity\ReceivedReportEmail;
use App\Services\CredentialEncryptor;
use App\Tests\Fixtures\Persona;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\TestSupport\FallbackCalloutStripping;
use App\Tests\WebTestCase;
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
 * Coverage for the TASK-035 changes to the mailboxes list page: rows become
 * clickable to the detail page via the stretched-link pattern, the "Re-test"
 * button stays clickable despite the overlay, and each row gains an inline
 * 30-day activity summary cell.
 */
final class MailboxesListTest extends WebTestCase
{
    use FallbackCalloutStripping;

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
            ->emailPrefix('mb-list-'.substr(Uuid::uuid7()->toString(), 0, 6))
            ->withDomain('mb-list.example')
            ->build();

        $encryptor = self::getContainer()->get(CredentialEncryptor::class);
        assert($encryptor instanceof CredentialEncryptor);

        $mailbox = new MailboxConnection(
            id: Uuid::uuid7(),
            team: $persona->team,
            type: MailboxType::ImapUser,
            host: 'imap.list.example',
            port: 993,
            encryptedUsername: $encryptor->encrypt('u'),
            encryptedPassword: $encryptor->encrypt('p'),
            encryption: MailboxEncryption::Ssl,
            createdAt: new \DateTimeImmutable(),
            lastPolledAt: new \DateTimeImmutable('-15 minutes'),
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
    public function rowsAreClickableToDetailPage(): void
    {
        $data = $this->bootClientWithMailbox();

        $crawler = $data['client']->request('GET', '/app/mailboxes');

        self::assertResponseIsSuccessful();
        // Stretched-link anchor inside the row points to detail page.
        $anchors = $crawler->filter('a[href="/app/mailboxes/'.$data['mailbox']->id->toString().'"]');
        self::assertGreaterThan(0, $anchors->count());

        // The stretched-link anchor MUST carry `absolute inset-0 z-10` so the
        // overlay covers the row but defers to z-20 inner controls.
        $stretched = $anchors->filter('.absolute.inset-0.z-10');
        self::assertGreaterThan(0, $stretched->count());
    }

    #[Test]
    public function retestButtonIsClickableDespiteStretchedLink(): void
    {
        $data = $this->bootClientWithMailbox();

        $crawler = $data['client']->request('GET', '/app/mailboxes');

        // The retest form must be above the stretched anchor's stacking
        // context. The form (or its container) carries `relative z-20`.
        $forms = $crawler->filter('form.relative.z-20[action="/app/mailboxes/'.$data['mailbox']->id->toString().'/test"]');
        self::assertGreaterThan(0, $forms->count());
    }

    #[Test]
    public function rowsShowInlineActivitySummary(): void
    {
        $data = $this->bootClientWithMailbox();
        $em = $data['em'];
        $persona = $data['persona'];
        assert(null !== $persona->domain);

        // Three envelopes in the last 30 days: 2 parsed, 1 unparsed.
        $env1 = $this->persistEnvelope($em, $data['mailbox'], new \DateTimeImmutable('-1 day'));
        $this->persistReport($em, $persona->domain, $env1);
        $env2 = $this->persistEnvelope($em, $data['mailbox'], new \DateTimeImmutable('-3 days'));
        $this->persistReport($em, $persona->domain, $env2);
        $this->persistEnvelope($em, $data['mailbox'], new \DateTimeImmutable('-10 days'));

        $data['client']->request('GET', '/app/mailboxes');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString('3 envelopes', $body);
        self::assertStringContainsString('2 reports', $body);
        self::assertStringContainsString('0 quarantined', $body);
        // Activity column header.
        self::assertStringContainsString('Activity (30d)', $body);
    }

    #[Test]
    public function rowsShowZeroActivitySummaryForFreshMailbox(): void
    {
        $data = $this->bootClientWithMailbox();

        $data['client']->request('GET', '/app/mailboxes');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString('0 envelopes / 0 reports / 0 quarantined', $body);
    }

    /**
     * Regression-net guard (TASK-090): the literal "Connect a mailbox" copy
     * is allowed inside the page's fallback callout only. Any other place on
     * the page surfacing the same string (e.g. a future EmptyState revert or
     * a header CTA) breaks the DNS-first hierarchy and must trip this test.
     */
    #[Test]
    public function unqualifiedMailboxCtaOnlyAppearsInFallbackCallout(): void
    {
        $data = $this->bootClientWithMailbox();

        $data['client']->request('GET', '/app/mailboxes');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();

        // DOM-based strip (see FallbackCalloutStripping). Layout-level
        // GlobalAddDropdown is intentionally exempt — see
        // ReportIngestionPageTest for the same exemption.
        $stripped = $this->stripFallbackCalloutAndGlobalDropdown($body);

        self::assertStringContainsString('Connect a mailbox', $body);
        self::assertStringNotContainsString('Connect a mailbox', $stripped);
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
            subject: 'List page fixture',
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
}
