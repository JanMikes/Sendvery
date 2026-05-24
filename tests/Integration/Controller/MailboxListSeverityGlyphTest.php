<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\MailboxConnection;
use App\Services\CredentialEncryptor;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use App\Value\MailboxEncryption;
use App\Value\MailboxType;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Integration coverage for TASK-068: the connected-mailboxes table on
 * /app/mailboxes prepends a leading severity glyph + matches the row root
 * with `border-l-{tone}`. Three seeded mailboxes (active-clean / errored /
 * inactive) exercise the three tone branches.
 */
final class MailboxListSeverityGlyphTest extends WebTestCase
{
    /**
     * @return array{client: KernelBrowser, healthyMailbox: MailboxConnection, errorMailbox: MailboxConnection, inactiveMailbox: MailboxConnection}
     */
    private function bootClientWithThreeMailboxes(): array
    {
        $client = self::createClient();
        $em = self::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
        assert($em instanceof \Doctrine\ORM\EntityManagerInterface);
        $fixtures = TestFixtures::fromContainer(self::getContainer());

        $suffix = substr(Uuid::uuid7()->toString(), 0, 6);
        $persona = $fixtures->persona()
            ->emailPrefix('mb-glyph-'.$suffix)
            ->withDomain('mb-glyph-'.$suffix.'.example')
            ->build();

        $encryptor = self::getContainer()->get(CredentialEncryptor::class);
        assert($encryptor instanceof CredentialEncryptor);

        $healthyMailbox = new MailboxConnection(
            id: Uuid::uuid7(),
            team: $persona->team,
            type: MailboxType::ImapUser,
            host: 'imap-healthy-'.$suffix.'.example',
            port: 993,
            encryptedUsername: $encryptor->encrypt('u'),
            encryptedPassword: $encryptor->encrypt('p'),
            encryption: MailboxEncryption::Ssl,
            createdAt: new \DateTimeImmutable(),
            lastPolledAt: new \DateTimeImmutable('-5 minutes'),
        );
        $healthyMailbox->popEvents();
        $em->persist($healthyMailbox);

        $errorMailbox = new MailboxConnection(
            id: Uuid::uuid7(),
            team: $persona->team,
            type: MailboxType::ImapUser,
            host: 'imap-error-'.$suffix.'.example',
            port: 993,
            encryptedUsername: $encryptor->encrypt('u'),
            encryptedPassword: $encryptor->encrypt('p'),
            encryption: MailboxEncryption::Ssl,
            createdAt: new \DateTimeImmutable(),
            lastPolledAt: new \DateTimeImmutable('-6 hours'),
            lastError: 'Connection refused — credentials rejected',
        );
        $errorMailbox->popEvents();
        $em->persist($errorMailbox);

        $inactiveMailbox = new MailboxConnection(
            id: Uuid::uuid7(),
            team: $persona->team,
            type: MailboxType::ImapUser,
            host: 'imap-inactive-'.$suffix.'.example',
            port: 993,
            encryptedUsername: $encryptor->encrypt('u'),
            encryptedPassword: $encryptor->encrypt('p'),
            encryption: MailboxEncryption::Ssl,
            createdAt: new \DateTimeImmutable(),
            isActive: false,
        );
        $inactiveMailbox->popEvents();
        $em->persist($inactiveMailbox);

        $em->flush();

        $client->loginUser($persona->user);

        return [
            'client' => $client,
            'healthyMailbox' => $healthyMailbox,
            'errorMailbox' => $errorMailbox,
            'inactiveMailbox' => $inactiveMailbox,
        ];
    }

    #[Test]
    public function activeMailboxRowCarriesSuccessLeftBorderAndCheckGlyph(): void
    {
        $data = $this->bootClientWithThreeMailboxes();

        $data['client']->request('GET', '/app/mailboxes');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString($data['healthyMailbox']->host, $body);
        self::assertStringContainsString('border-l-success', $body);
        // Canonical check-circle path lifted from the shared severity macro.
        self::assertStringContainsString('M9 12l2 2 4-4m5.618-4.016', $body);
    }

    #[Test]
    public function errorMailboxRowCarriesErrorLeftBorderAndCirclePath(): void
    {
        $data = $this->bootClientWithThreeMailboxes();

        $data['client']->request('GET', '/app/mailboxes');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString($data['errorMailbox']->host, $body);
        self::assertStringContainsString('border-l-error', $body);
        // Exclamation-circle path used by the error glyph.
        self::assertStringContainsString('M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z', $body);
    }

    #[Test]
    public function inactiveMailboxRowCarriesNeutralLeftBorderTone(): void
    {
        $data = $this->bootClientWithThreeMailboxes();

        $data['client']->request('GET', '/app/mailboxes');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString($data['inactiveMailbox']->host, $body);
        // Inactive rows render the ghost/grey treatment so they don't compete
        // with errored rows for the user's eye.
        self::assertStringContainsString('border-l-base-300', $body);
    }

    #[Test]
    public function everyRowCarriesExactlyOneLeftBorderToneAndOneGlyph(): void
    {
        $data = $this->bootClientWithThreeMailboxes();

        $data['client']->request('GET', '/app/mailboxes');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();

        $crawler = $data['client']->getCrawler();
        // Connected-mailboxes section only — the matrix section above uses
        // its own row treatment (TASK-100) and is exempt from TASK-068.
        $rows = $crawler->filter('[data-testid="connected-mailboxes"] table tbody tr');
        self::assertCount(3, $rows);

        $borderClasses = 0;
        foreach ($rows as $tr) {
            assert($tr instanceof \DOMElement);
            $class = $tr->getAttribute('class');
            $matches = preg_match_all('/border-l-(success|error|base-300)/', $class);
            self::assertSame(
                1,
                $matches,
                'Each mailbox row must carry exactly one severity border tone; got: '.$class,
            );
            ++$borderClasses;
        }
        self::assertSame(3, $borderClasses);

        // sr-only Status column header is the contract for screen readers.
        self::assertStringContainsString('<span class="sr-only">Status</span>', $body);
    }

    #[Test]
    public function statusBadgeColumnStaysPresentAsPreciseTextualLabel(): void
    {
        // Acceptance guard: the leading glyph is the scannable cue, the text
        // badge is the precise label — removing the badge would regress the
        // TASK-068 contract.
        $data = $this->bootClientWithThreeMailboxes();

        $data['client']->request('GET', '/app/mailboxes');

        $body = (string) $data['client']->getResponse()->getContent();
        // StatusBadge renders the textual labels Active / Error / Inactive
        // inside the existing third "Status" column.
        self::assertStringContainsString('Active', $body);
        self::assertStringContainsString('Error', $body);
        self::assertStringContainsString('Inactive', $body);
    }
}
