<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\MailboxConnection;
use App\Services\CredentialEncryptor;
use App\Services\Mail\FakeMailClient;
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

final class RetestMailboxConnectionTest extends WebTestCase
{
    /**
     * @return array{client: KernelBrowser, em: EntityManagerInterface, persona: Persona, fakeClient: FakeMailClient, mailboxId: UuidInterface}
     */
    private function bootClientWithMailbox(string $emailPrefix = 'retest'): array
    {
        $client = self::createClient();
        // Persist FakeMailClient state across requests.
        $client->disableReboot();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $fixtures = TestFixtures::fromContainer(self::getContainer());

        $persona = $fixtures->persona()
            ->emailPrefix($emailPrefix.'-'.substr(uniqid('', true), -6))
            ->withDomain('retest-'.substr(uniqid('', true), -6).'.example')
            ->build();

        $encryptor = self::getContainer()->get(CredentialEncryptor::class);
        assert($encryptor instanceof CredentialEncryptor);

        $mailboxId = Uuid::uuid7();
        $mailbox = new MailboxConnection(
            id: $mailboxId,
            team: $persona->team,
            type: MailboxType::ImapUser,
            host: 'imap.example.com',
            port: 993,
            encryptedUsername: $encryptor->encrypt('user@example.com'),
            encryptedPassword: $encryptor->encrypt('pass'),
            encryption: MailboxEncryption::Ssl,
            createdAt: new \DateTimeImmutable(),
        );
        $mailbox->popEvents();
        $em->persist($mailbox);
        $em->flush();

        $client->loginUser($persona->user);

        $fakeClient = self::getContainer()->get(FakeMailClient::class);
        assert($fakeClient instanceof FakeMailClient);
        $fakeClient->reset();

        return [
            'client' => $client,
            'em' => $em,
            'persona' => $persona,
            'fakeClient' => $fakeClient,
            'mailboxId' => $mailboxId,
        ];
    }

    private function csrfToken(KernelBrowser $client): string
    {
        $client->request('GET', '/app/mailboxes');
        $crawler = $client->getCrawler();
        $token = $crawler->filter('input[name="_csrf_token"]')->first()->attr('value');
        self::assertNotEmpty($token);

        return $token;
    }

    #[Test]
    public function successfulRetestFlashesSuccess(): void
    {
        $data = $this->bootClientWithMailbox();
        $token = $this->csrfToken($data['client']);

        $data['client']->request('POST', '/app/mailboxes/'.$data['mailboxId'].'/test', [
            '_csrf_token' => $token,
        ]);

        self::assertResponseRedirects('/app/mailboxes');
        $data['client']->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Connection is working');
    }

    #[Test]
    public function failedRetestFlashesError(): void
    {
        $data = $this->bootClientWithMailbox('retest-fail');
        $token = $this->csrfToken($data['client']);
        $data['fakeClient']->simulateFailure(
            'Authentication failed for user@example.com',
            \App\Value\MailboxConnectionErrorCode::AuthenticationFailed,
        );

        $data['client']->request('POST', '/app/mailboxes/'.$data['mailboxId'].'/test', [
            '_csrf_token' => $token,
        ]);

        self::assertResponseRedirects('/app/mailboxes');
        $data['client']->followRedirect();
        self::assertSelectorTextContains('.alert-error', 'Authentication failed');
        // Must NOT leak the raw IMAP error string (which may contain creds).
        self::assertSelectorTextNotContains('.alert-error', 'user@example.com');
    }

    #[Test]
    public function unknownIdReturns404(): void
    {
        $data = $this->bootClientWithMailbox('retest-404');
        $token = $this->csrfToken($data['client']);
        $unknownId = Uuid::uuid7()->toString();

        $data['client']->request('POST', '/app/mailboxes/'.$unknownId.'/test', [
            '_csrf_token' => $token,
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function crossTenantMailboxReturns404(): void
    {
        $data = $this->bootClientWithMailbox('retest-other');
        $em = $data['em'];

        // Create another team with its own mailbox.
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $other = $fixtures->persona()
            ->emailPrefix('retest-other-victim-'.substr(uniqid('', true), -6))
            ->withDomain('other-'.substr(uniqid('', true), -6).'.example')
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
            encryptedUsername: $encryptor->encrypt('victim@example.com'),
            encryptedPassword: $encryptor->encrypt('pass'),
            encryption: MailboxEncryption::Ssl,
            createdAt: new \DateTimeImmutable(),
        );
        $otherMailbox->popEvents();
        $em->persist($otherMailbox);
        $em->flush();

        $token = $this->csrfToken($data['client']);

        $data['client']->request('POST', '/app/mailboxes/'.$otherMailboxId.'/test', [
            '_csrf_token' => $token,
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function missingCsrfTokenIsRejected(): void
    {
        $data = $this->bootClientWithMailbox('retest-no-csrf');

        $data['client']->request('POST', '/app/mailboxes/'.$data['mailboxId'].'/test');

        self::assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function invalidCsrfTokenIsRejected(): void
    {
        $data = $this->bootClientWithMailbox('retest-bad-csrf');

        $data['client']->request('POST', '/app/mailboxes/'.$data['mailboxId'].'/test', [
            '_csrf_token' => 'definitely-not-real',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function getMethodIsRejected(): void
    {
        $data = $this->bootClientWithMailbox('retest-get');

        $data['client']->request('GET', '/app/mailboxes/'.$data['mailboxId'].'/test');

        self::assertResponseStatusCodeSame(405);
    }

    #[Test]
    public function unauthenticatedRequestRedirectsToLogin(): void
    {
        $client = self::createClient();
        $someId = Uuid::uuid7()->toString();

        $client->request('POST', '/app/mailboxes/'.$someId.'/test', [
            '_csrf_token' => 'whatever',
        ]);

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }
}
