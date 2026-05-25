<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\MailboxConnection;
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
 * End-to-end coverage for TASK-094's advisor card on `/app/mailboxes/{id}`.
 * Each non-healthy branch persists the matching state, renders the page, and
 * asserts the card surfaces with the right severity. The healthy regression
 * test pins the absence of the card so the next refactor can't sneak it
 * back into the always-on state.
 */
final class MailboxHealthAdvisorCardTest extends WebTestCase
{
    /**
     * @return array{client: KernelBrowser, em: EntityManagerInterface, persona: Persona}
     */
    private function bootClient(): array
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->persona()
            ->emailPrefix('mb-advisor-'.substr(Uuid::uuid7()->toString(), 0, 6))
            ->withDomain('advisor.example')
            ->build();

        $client->loginUser($persona->user);

        return ['client' => $client, 'em' => $em, 'persona' => $persona];
    }

    #[Test]
    public function brokenCredentialsCardRendersForMailboxWithLastError(): void
    {
        $boot = $this->bootClient();
        $mailbox = $this->persistMailbox(
            $boot['em'],
            $boot['persona'],
            createdAt: new \DateTimeImmutable('-30 days'),
            lastPolledAt: new \DateTimeImmutable('-10 minutes'),
            lastError: 'IMAP authentication failed (LOGIN rejected)',
        );

        $crawler = $boot['client']->request('GET', '/app/mailboxes/'.$mailbox->id->toString());

        self::assertResponseIsSuccessful();
        $card = $crawler->filter('[data-testid="mailbox-health-advisor"]');
        self::assertGreaterThan(0, $card->count(), 'Advisor card must render for broken credentials.');
        self::assertSame('broken_credentials', (string) $card->attr('data-severity'));
        self::assertStringContainsString(
            "Sendvery can't log in",
            (string) $crawler->filter('[data-testid="mailbox-health-advisor-headline"]')->text(),
        );
        self::assertStringContainsString(
            'IMAP authentication failed',
            (string) $crawler->filter('[data-testid="mailbox-health-advisor-reason"]')->text(),
        );
        // The re-test CTA is a POST form (CSRF-guarded), not a link.
        self::assertGreaterThan(
            0,
            $crawler->filter('form[action="/app/mailboxes/'.$mailbox->id->toString().'/test"] button[data-testid="mailbox-health-advisor-primary-cta"]')->count(),
        );
    }

    #[Test]
    public function silentForTooLongCardRendersWhenPolledLongerThan7DaysWithNoEnvelopes(): void
    {
        $boot = $this->bootClient();
        $mailbox = $this->persistMailbox(
            $boot['em'],
            $boot['persona'],
            createdAt: new \DateTimeImmutable('-14 days'),
            lastPolledAt: new \DateTimeImmutable('-10 minutes'),
            lastError: null,
        );

        $crawler = $boot['client']->request('GET', '/app/mailboxes/'.$mailbox->id->toString());

        self::assertResponseIsSuccessful();
        $card = $crawler->filter('[data-testid="mailbox-health-advisor"]');
        self::assertGreaterThan(0, $card->count());
        self::assertSame('silent_for_too_long', (string) $card->attr('data-severity'));
        self::assertStringContainsString(
            'No new reports arriving',
            (string) $crawler->filter('[data-testid="mailbox-health-advisor-headline"]')->text(),
        );

        // Primary CTA → domains overview (TASK-130: dns-health merged into domains).
        $primary = $crawler->filter('a[data-testid="mailbox-health-advisor-primary-cta"]');
        self::assertGreaterThan(0, $primary->count());
        self::assertStringContainsString('/app/domains', (string) $primary->attr('href'));

        // Secondary link → mailboxes landing (TASK-090 callout).
        $secondary = $crawler->filter('[data-testid="mailbox-health-advisor-secondary-link"]');
        self::assertGreaterThan(0, $secondary->count());
        self::assertStringContainsString('/app/mailboxes', (string) $secondary->attr('href'));
    }

    #[Test]
    public function quarantineDominantCardRendersWhenMostEnvelopesAreQuarantined(): void
    {
        $boot = $this->bootClient();
        $persona = $boot['persona'];
        assert(null !== $persona->domain);
        $em = $boot['em'];

        $mailbox = $this->persistMailbox(
            $em,
            $persona,
            createdAt: new \DateTimeImmutable('-20 days'),
            lastPolledAt: new \DateTimeImmutable('-5 minutes'),
            lastError: null,
        );

        // 12 envelopes total, 8 quarantined — clears both the >=10 floor and
        // the >50% dominance threshold.
        for ($i = 0; $i < 12; ++$i) {
            $envelope = $this->persistEnvelope($em, $mailbox, new \DateTimeImmutable('-'.($i + 1).' days'));
            if ($i < 8) {
                $this->persistQuarantine($em, $envelope, $persona->domain->domain);
            }
        }

        $crawler = $boot['client']->request('GET', '/app/mailboxes/'.$mailbox->id->toString());

        self::assertResponseIsSuccessful();
        $card = $crawler->filter('[data-testid="mailbox-health-advisor"]');
        self::assertGreaterThan(0, $card->count());
        self::assertSame('quarantine_dominant', (string) $card->attr('data-severity'));

        // Primary CTA → quarantine list filtered to this mailbox.
        $primary = $crawler->filter('a[data-testid="mailbox-health-advisor-primary-cta"]');
        self::assertGreaterThan(0, $primary->count());
        $href = (string) $primary->attr('href');
        self::assertStringContainsString('/app/quarantine', $href);
        self::assertStringContainsString('mailbox='.$mailbox->id->toString(), $href);
    }

    #[Test]
    public function noAdvisorCardForHealthyMailbox(): void
    {
        $boot = $this->bootClient();
        // Freshly connected mailbox — never polled, never errored, no envelopes
        // expected yet. Below all eligibility thresholds, advisor stays silent.
        $mailbox = $this->persistMailbox(
            $boot['em'],
            $boot['persona'],
            createdAt: new \DateTimeImmutable('-1 day'),
            lastPolledAt: null,
            lastError: null,
        );

        $crawler = $boot['client']->request('GET', '/app/mailboxes/'.$mailbox->id->toString());

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-testid="mailbox-health-advisor"]'));
    }

    private function persistMailbox(
        EntityManagerInterface $em,
        Persona $persona,
        \DateTimeImmutable $createdAt,
        ?\DateTimeImmutable $lastPolledAt,
        ?string $lastError,
    ): MailboxConnection {
        $encryptor = self::getContainer()->get(CredentialEncryptor::class);
        assert($encryptor instanceof CredentialEncryptor);

        $mailbox = new MailboxConnection(
            id: Uuid::uuid7(),
            team: $persona->team,
            type: MailboxType::ImapUser,
            host: 'imap.advisor.example',
            port: 993,
            encryptedUsername: $encryptor->encrypt('user@advisor.example'),
            encryptedPassword: $encryptor->encrypt('s3cret'),
            encryption: MailboxEncryption::Ssl,
            createdAt: $createdAt,
            lastPolledAt: $lastPolledAt,
            lastError: $lastError,
        );
        $mailbox->popEvents();
        $em->persist($mailbox);
        $em->flush();

        return $mailbox;
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
            subject: 'DMARC advisor fixture',
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
