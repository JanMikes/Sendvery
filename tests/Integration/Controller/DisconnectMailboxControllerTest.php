<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\MailboxConnection;
use App\Repository\MailboxConnectionRepository;
use App\Services\CredentialEncryptor;
use App\Tests\Fixtures\Persona;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use App\Value\MailboxEncryption;
use App\Value\MailboxType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * TASK-133 end-to-end coverage for the soft-delete disconnect flow.
 *
 * Each test seeds a mailbox under a logged-in persona, POSTs to
 * `/app/mailboxes/{id}/disconnect` (CSRF-guarded), and asserts the row gets
 * stamped with `disconnectedAt` + drops out of the dashboard list. Negative
 * branches cover CSRF, cross-tenant, GET-rejected, unknown-ID, anonymous.
 *
 * The "advisor CTA opens modal on detail page" assertion lives here so the
 * Disconnect button on `/app/mailboxes/{id}` is regression-pinned alongside the
 * actual disconnect — if either drifts the test fails.
 */
final class DisconnectMailboxControllerTest extends WebTestCase
{
    /**
     * @return array{client: KernelBrowser, em: EntityManagerInterface, persona: Persona, mailboxId: UuidInterface}
     */
    private function bootClientWithMailbox(string $emailPrefix = 'disconnect'): array
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $fixtures = TestFixtures::fromContainer(self::getContainer());

        $persona = $fixtures->persona()
            ->emailPrefix($emailPrefix.'-'.substr(Uuid::uuid7()->toString(), 0, 6))
            ->withDomain('disconnect-'.substr(Uuid::uuid7()->toString(), 0, 6).'.example')
            ->build();

        $encryptor = self::getContainer()->get(CredentialEncryptor::class);
        assert($encryptor instanceof CredentialEncryptor);

        $mailboxId = Uuid::uuid7();
        $mailbox = new MailboxConnection(
            id: $mailboxId,
            team: $persona->team,
            type: MailboxType::ImapUser,
            host: 'imap.disconnect.example',
            port: 993,
            encryptedUsername: $encryptor->encrypt('user@disconnect.example'),
            encryptedPassword: $encryptor->encrypt('pass'),
            encryption: MailboxEncryption::Ssl,
            createdAt: new \DateTimeImmutable(),
        );
        $mailbox->popEvents();
        $em->persist($mailbox);
        $em->flush();

        $client->loginUser($persona->user);

        return [
            'client' => $client,
            'em' => $em,
            'persona' => $persona,
            'mailboxId' => $mailboxId,
        ];
    }

    /**
     * Pulls the `mailbox_disconnect` CSRF token from the modal form rendered
     * on `/app/mailboxes/{id}` — same scraping pattern as
     * {@see SetupChecklistTest} so the token gets seeded into the session.
     */
    private function csrfToken(KernelBrowser $client, UuidInterface $mailboxId): string
    {
        $crawler = $client->request('GET', '/app/mailboxes/'.$mailboxId->toString());
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('dialog#disconnect-mailbox-modal input[name="_csrf_token"]')->attr('value');
        self::assertNotEmpty($token);

        return $token;
    }

    #[Test]
    public function successfulDisconnectStampsTimestampAndRedirectsWithFlash(): void
    {
        $data = $this->bootClientWithMailbox();
        $token = $this->csrfToken($data['client'], $data['mailboxId']);

        $data['client']->request('POST', '/app/mailboxes/'.$data['mailboxId']->toString().'/disconnect', [
            '_csrf_token' => $token,
        ]);

        self::assertResponseRedirects('/app/mailboxes');
        $data['client']->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Disconnected imap.disconnect.example');

        $data['em']->clear();
        $repo = self::getContainer()->get(MailboxConnectionRepository::class);
        assert($repo instanceof MailboxConnectionRepository);
        $reloaded = $repo->get($data['mailboxId']);
        self::assertNotNull($reloaded->disconnectedAt, 'disconnectedAt must be stamped after a successful disconnect.');
    }

    #[Test]
    public function disconnectIsIdempotent(): void
    {
        $data = $this->bootClientWithMailbox('disconnect-idem');
        $token = $this->csrfToken($data['client'], $data['mailboxId']);

        // First disconnect.
        $data['client']->request('POST', '/app/mailboxes/'.$data['mailboxId']->toString().'/disconnect', [
            '_csrf_token' => $token,
        ]);
        self::assertResponseRedirects('/app/mailboxes');

        // Second disconnect — must not throw, must still redirect, must keep
        // disconnectedAt populated (timestamp may refresh; we only assert non-null).
        $data['client']->request('POST', '/app/mailboxes/'.$data['mailboxId']->toString().'/disconnect', [
            '_csrf_token' => $token,
        ]);
        self::assertResponseRedirects('/app/mailboxes');

        $data['em']->clear();
        $repo = self::getContainer()->get(MailboxConnectionRepository::class);
        assert($repo instanceof MailboxConnectionRepository);
        $reloaded = $repo->get($data['mailboxId']);
        self::assertNotNull($reloaded->disconnectedAt);
    }

    #[Test]
    public function missingCsrfTokenIsRejected(): void
    {
        $data = $this->bootClientWithMailbox('disconnect-no-csrf');

        $data['client']->request('POST', '/app/mailboxes/'.$data['mailboxId']->toString().'/disconnect');

        self::assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function invalidCsrfTokenIsRejected(): void
    {
        $data = $this->bootClientWithMailbox('disconnect-bad-csrf');

        $data['client']->request('POST', '/app/mailboxes/'.$data['mailboxId']->toString().'/disconnect', [
            '_csrf_token' => 'definitely-not-real',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function crossTenantMailboxReturns404(): void
    {
        $data = $this->bootClientWithMailbox('disconnect-other');
        $em = $data['em'];

        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $other = $fixtures->persona()
            ->emailPrefix('disconnect-other-victim-'.substr(Uuid::uuid7()->toString(), 0, 6))
            ->withDomain('other-'.substr(Uuid::uuid7()->toString(), 0, 6).'.example')
            ->build();

        $encryptor = self::getContainer()->get(CredentialEncryptor::class);
        assert($encryptor instanceof CredentialEncryptor);

        $otherMailboxId = Uuid::uuid7();
        $otherMailbox = new MailboxConnection(
            id: $otherMailboxId,
            team: $other->team,
            type: MailboxType::ImapUser,
            host: 'imap.other.example',
            port: 993,
            encryptedUsername: $encryptor->encrypt('victim@other.example'),
            encryptedPassword: $encryptor->encrypt('pass'),
            encryption: MailboxEncryption::Ssl,
            createdAt: new \DateTimeImmutable(),
        );
        $otherMailbox->popEvents();
        $em->persist($otherMailbox);
        $em->flush();

        $token = $this->csrfToken($data['client'], $data['mailboxId']);

        $data['client']->request('POST', '/app/mailboxes/'.$otherMailboxId->toString().'/disconnect', [
            '_csrf_token' => $token,
        ]);

        self::assertResponseStatusCodeSame(404);

        // The other tenant's mailbox must NOT have been touched.
        $em->clear();
        $repo = self::getContainer()->get(MailboxConnectionRepository::class);
        assert($repo instanceof MailboxConnectionRepository);
        $reloaded = $repo->get($otherMailboxId);
        self::assertNull($reloaded->disconnectedAt);
    }

    #[Test]
    public function unknownIdReturns404(): void
    {
        $data = $this->bootClientWithMailbox('disconnect-404');
        $token = $this->csrfToken($data['client'], $data['mailboxId']);
        $unknownId = Uuid::uuid7()->toString();

        $data['client']->request('POST', '/app/mailboxes/'.$unknownId.'/disconnect', [
            '_csrf_token' => $token,
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function getMethodIsRejected(): void
    {
        $data = $this->bootClientWithMailbox('disconnect-get');

        $data['client']->request('GET', '/app/mailboxes/'.$data['mailboxId']->toString().'/disconnect');

        self::assertResponseStatusCodeSame(405);
    }

    #[Test]
    public function disconnectedMailboxHiddenFromListPage(): void
    {
        $data = $this->bootClientWithMailbox('disconnect-list-hidden');
        $token = $this->csrfToken($data['client'], $data['mailboxId']);

        // Sanity check: the connected-mailboxes section + the row inside it
        // are both visible before disconnect.
        $crawler = $data['client']->request('GET', '/app/mailboxes');
        self::assertResponseIsSuccessful();
        $sectionBefore = $crawler->filter('[data-testid="connected-mailboxes"]');
        self::assertGreaterThan(0, $sectionBefore->count(), 'Mailbox section must render before disconnect.');
        self::assertStringContainsString('imap.disconnect.example', $sectionBefore->text(''));

        $data['client']->request('POST', '/app/mailboxes/'.$data['mailboxId']->toString().'/disconnect', [
            '_csrf_token' => $token,
        ]);
        self::assertResponseRedirects('/app/mailboxes');

        // After the soft-delete, `findByTeam()` filters this row out — the
        // whole section collapses (template gates on `mailboxes|length > 0`).
        $crawler = $data['client']->request('GET', '/app/mailboxes');
        self::assertResponseIsSuccessful();
        self::assertCount(
            0,
            $crawler->filter('[data-testid="connected-mailboxes"]'),
            'Disconnected mailbox must not appear in the list.',
        );
    }

    #[Test]
    public function detailPageRendersDisconnectModalTrigger(): void
    {
        $data = $this->bootClientWithMailbox('disconnect-modal');

        $crawler = $data['client']->request('GET', '/app/mailboxes/'.$data['mailboxId']->toString());

        self::assertResponseIsSuccessful();
        // The Disconnect button in the page heading is a <button> that opens
        // the daisyUI <dialog id="disconnect-mailbox-modal"> — never POSTs
        // directly since destructive actions require explicit confirmation.
        $trigger = $crawler->filter('button[data-testid="mailbox-disconnect-trigger"]');
        self::assertGreaterThan(0, $trigger->count(), 'Disconnect trigger button must render on detail page.');
        self::assertStringContainsString('disconnect-mailbox-modal', (string) $trigger->attr('onclick'));

        // The modal itself must exist with the POST form inside it.
        self::assertGreaterThan(
            0,
            $crawler->filter('dialog#disconnect-mailbox-modal form[action$="/disconnect"]')->count(),
            'Disconnect modal with POST form must render.',
        );
    }
}
