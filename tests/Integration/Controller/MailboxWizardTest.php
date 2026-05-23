<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\MailboxConnection;
use App\Services\Mailbox\FakeMailboxConnectionTester;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use App\Value\MailboxConnectionErrorCode;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class MailboxWizardTest extends WebTestCase
{
    /**
     * @return array{client: KernelBrowser, em: EntityManagerInterface, teamId: \Ramsey\Uuid\UuidInterface, fakeTester: FakeMailboxConnectionTester}
     */
    private function bootClient(string $emailPrefix = 'mailbox-wizard'): array
    {
        $client = self::createClient();
        // Persist FakeMailboxConnectionTester state across requests.
        $client->disableReboot();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $fixtures = TestFixtures::fromContainer(self::getContainer());

        $persona = $fixtures->persona()
            ->emailPrefix($emailPrefix.'-'.substr(uniqid('', true), -6))
            ->withDomain('mailbox-'.substr(uniqid('', true), -6).'.example')
            ->build();

        $client->loginUser($persona->user);

        $fakeTester = self::getContainer()->get(FakeMailboxConnectionTester::class);
        assert($fakeTester instanceof FakeMailboxConnectionTester);
        $fakeTester->reset();

        return [
            'client' => $client,
            'em' => $em,
            'teamId' => $persona->team->id,
            'fakeTester' => $fakeTester,
        ];
    }

    private function csrfToken(KernelBrowser $client): string
    {
        $client->request('GET', '/app/mailboxes/add');
        $crawler = $client->getCrawler();
        $token = $crawler->filter('input[name="_csrf_token"]')->attr('value');
        self::assertNotEmpty($token);

        return $token;
    }

    private function countMailboxes(EntityManagerInterface $em): int
    {
        return (int) $em->getRepository(MailboxConnection::class)
            ->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    #[Test]
    public function addMailboxPageRenders(): void
    {
        $data = $this->bootClient();

        $data['client']->request('GET', '/app/mailboxes/add');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', 'Connect a mailbox');
    }

    #[Test]
    public function addMailboxPageEmbedsPresetsJson(): void
    {
        $data = $this->bootClient();

        $data['client']->request('GET', '/app/mailboxes/add');

        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString('data-mailbox-preset-presets-value', $body);
        self::assertStringContainsString('imap.gmail.com', $body);
        self::assertStringContainsString('outlook.office365.com', $body);
        self::assertStringContainsString('imap.fastmail.com', $body);
        self::assertStringContainsString('imap.mail.yahoo.com', $body);
        self::assertStringContainsString('imap.seznam.cz', $body);
    }

    #[Test]
    public function happyPathPersistsAndRedirectsToList(): void
    {
        $data = $this->bootClient('mailbox-happy');
        $token = $this->csrfToken($data['client']);
        $data['fakeTester']->willSucceed();

        $before = $this->countMailboxes($data['em']);

        $data['client']->request('POST', '/app/mailboxes/add', [
            '_csrf_token' => $token,
            'preset' => 'gmail',
            'type' => 'imap_user',
            'host' => 'imap.gmail.com',
            'port' => 993,
            'encryption' => 'ssl',
            'username' => 'me@example.com',
            'password' => 'app-password',
        ]);

        self::assertResponseRedirects('/app/mailboxes');
        self::assertSame($before + 1, $this->countMailboxes($data['em']));

        $data['client']->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Mailbox connected successfully');
    }

    #[Test]
    public function authFailureRendersInlineErrorAndDoesNotPersist(): void
    {
        $data = $this->bootClient('mailbox-auth-fail');
        $token = $this->csrfToken($data['client']);
        $data['fakeTester']->willFail(MailboxConnectionErrorCode::AuthenticationFailed);

        $before = $this->countMailboxes($data['em']);

        $data['client']->request('POST', '/app/mailboxes/add', [
            '_csrf_token' => $token,
            'preset' => 'custom',
            'type' => 'imap_user',
            'host' => 'imap.example.com',
            'port' => 993,
            'encryption' => 'ssl',
            'username' => 'wrong@example.com',
            'password' => 'wrong-pass',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame($before, $this->countMailboxes($data['em']));
        self::assertSelectorTextContains('.alert-error', 'Authentication failed');
    }

    #[Test]
    public function connectionRefusedRendersInlineError(): void
    {
        $data = $this->bootClient('mailbox-refused');
        $token = $this->csrfToken($data['client']);
        $data['fakeTester']->willFail(MailboxConnectionErrorCode::ConnectionRefused);

        $data['client']->request('POST', '/app/mailboxes/add', [
            '_csrf_token' => $token,
            'preset' => 'custom',
            'type' => 'imap_user',
            'host' => 'imap.example.com',
            'port' => 993,
            'encryption' => 'ssl',
            'username' => 'me@example.com',
            'password' => 'pass',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-error', 'refused');
    }

    #[Test]
    public function connectionTimeoutRendersInlineError(): void
    {
        $data = $this->bootClient('mailbox-timeout');
        $token = $this->csrfToken($data['client']);
        $data['fakeTester']->willFail(MailboxConnectionErrorCode::ConnectionTimeout);

        $data['client']->request('POST', '/app/mailboxes/add', [
            '_csrf_token' => $token,
            'preset' => 'custom',
            'type' => 'imap_user',
            'host' => 'imap.example.com',
            'port' => 993,
            'encryption' => 'ssl',
            'username' => 'me@example.com',
            'password' => 'pass',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-error', 'timed out');
    }

    #[Test]
    public function starttlsNotSupportedRendersInlineError(): void
    {
        $data = $this->bootClient('mailbox-starttls');
        $token = $this->csrfToken($data['client']);
        $data['fakeTester']->willFail(MailboxConnectionErrorCode::StarttlsNotSupported);

        $data['client']->request('POST', '/app/mailboxes/add', [
            '_csrf_token' => $token,
            'preset' => 'custom',
            'type' => 'imap_user',
            'host' => 'imap.example.com',
            'port' => 143,
            'encryption' => 'starttls',
            'username' => 'me@example.com',
            'password' => 'pass',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-error', 'STARTTLS');
    }

    #[Test]
    public function inboxNotFoundRendersInlineError(): void
    {
        $data = $this->bootClient('mailbox-inbox');
        $token = $this->csrfToken($data['client']);
        $data['fakeTester']->willFail(MailboxConnectionErrorCode::InboxNotFound);

        $data['client']->request('POST', '/app/mailboxes/add', [
            '_csrf_token' => $token,
            'preset' => 'custom',
            'type' => 'imap_user',
            'host' => 'imap.example.com',
            'port' => 993,
            'encryption' => 'ssl',
            'username' => 'me@example.com',
            'password' => 'pass',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-error', 'INBOX folder');
    }

    #[Test]
    public function unknownErrorRendersInlineError(): void
    {
        $data = $this->bootClient('mailbox-unknown');
        $token = $this->csrfToken($data['client']);
        $data['fakeTester']->willFail(MailboxConnectionErrorCode::Unknown);

        $data['client']->request('POST', '/app/mailboxes/add', [
            '_csrf_token' => $token,
            'preset' => 'custom',
            'type' => 'imap_user',
            'host' => 'imap.example.com',
            'port' => 993,
            'encryption' => 'ssl',
            'username' => 'me@example.com',
            'password' => 'pass',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-error', 'Could not connect');
    }

    #[Test]
    public function validationFailureSkipsTesterAndShowsErrors(): void
    {
        $data = $this->bootClient('mailbox-validation');
        $token = $this->csrfToken($data['client']);
        $data['fakeTester']->willSucceed();

        $before = $this->countMailboxes($data['em']);

        $data['client']->request('POST', '/app/mailboxes/add', [
            '_csrf_token' => $token,
            'preset' => 'custom',
            'type' => 'imap_user',
            'host' => '',
            'port' => 993,
            'encryption' => 'ssl',
            'username' => '',
            'password' => '',
        ]);

        self::assertResponseIsSuccessful();
        self::assertFalse($data['fakeTester']->wasInvoked());
        self::assertSame($before, $this->countMailboxes($data['em']));
        self::assertSelectorExists('.alert-error');
    }

    #[Test]
    public function missingCsrfTokenIsRejected(): void
    {
        $data = $this->bootClient('mailbox-no-csrf');

        $data['client']->request('POST', '/app/mailboxes/add', [
            'preset' => 'custom',
            'type' => 'imap_user',
            'host' => 'imap.example.com',
            'port' => 993,
            'encryption' => 'ssl',
            'username' => 'me@example.com',
            'password' => 'pass',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function invalidCsrfTokenIsRejected(): void
    {
        $data = $this->bootClient('mailbox-bad-csrf');

        $data['client']->request('POST', '/app/mailboxes/add', [
            '_csrf_token' => 'definitely-not-real',
            'preset' => 'custom',
            'type' => 'imap_user',
            'host' => 'imap.example.com',
            'port' => 993,
            'encryption' => 'ssl',
            'username' => 'me@example.com',
            'password' => 'pass',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function unauthenticatedRequestRedirectsToLogin(): void
    {
        $client = self::createClient();

        $client->request('GET', '/app/mailboxes/add');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    #[Test]
    public function appPasswordBannerVisibleWhenGmailSelectedAfterFailure(): void
    {
        $data = $this->bootClient('mailbox-banner');
        $token = $this->csrfToken($data['client']);
        $data['fakeTester']->willFail(MailboxConnectionErrorCode::AuthenticationFailed);

        $data['client']->request('POST', '/app/mailboxes/add', [
            '_csrf_token' => $token,
            'preset' => 'gmail',
            'type' => 'imap_user',
            'host' => 'imap.gmail.com',
            'port' => 993,
            'encryption' => 'ssl',
            'username' => 'me@example.com',
            'password' => 'wrong',
        ]);

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        // Banner is rendered without the `hidden` attribute when gmail is selected
        self::assertMatchesRegularExpression(
            '/data-mailbox-preset-target="banner"(?![^>]*\bhidden\b)/',
            $body,
        );
    }
}
