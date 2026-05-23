<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\KnownSender;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Entity\TeamMembership;
use App\Entity\User;
use App\Tests\WebTestCase;
use App\Value\TeamRole;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class SenderInventoryActionsTest extends WebTestCase
{
    /**
     * @return array{
     *     client: KernelBrowser,
     *     em: EntityManagerInterface,
     *     team: Team,
     *     user: User,
     *     domain: MonitoredDomain,
     *     senderId: UuidInterface,
     *     foreignSenderId: UuidInterface
     * }
     */
    private function bootClientWithSender(): array
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $user = new User(
            id: Uuid::uuid7(),
            email: 'sender-actions-'.Uuid::uuid7()->toString().'@example.com',
            createdAt: new \DateTimeImmutable(),
            onboardingCompletedAt: new \DateTimeImmutable(),
        );
        $user->popEvents();
        $em->persist($user);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Sender Actions Team',
            slug: 'sender-actions-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $team->popEvents();
        $em->persist($team);

        $em->persist(new TeamMembership(
            id: Uuid::uuid7(),
            user: $user,
            team: $team,
            role: TeamRole::Owner,
            joinedAt: new \DateTimeImmutable(),
        ));

        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'senders-'.Uuid::uuid7()->toString().'.com',
            createdAt: new \DateTimeImmutable(),
        );
        $domain->popEvents();
        $em->persist($domain);

        $senderId = Uuid::uuid7();
        $sender = new KnownSender(
            id: $senderId,
            monitoredDomain: $domain,
            sourceIp: '1.2.3.4',
            firstSeenAt: new \DateTimeImmutable('2026-01-01'),
            lastSeenAt: new \DateTimeImmutable('2026-05-01'),
            totalMessages: 1000,
            passRate: 95.0,
            hostname: 'mail.example.com',
            organization: 'Example',
        );
        $em->persist($sender);

        // Foreign tenant — same shape, owned by a separate team the user
        // is NOT a member of. Used to assert cross-tenant safety.
        $foreignTeam = new Team(
            id: Uuid::uuid7(),
            name: 'Foreign',
            slug: 'foreign-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $foreignTeam->popEvents();
        $em->persist($foreignTeam);

        $foreignDomain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $foreignTeam,
            domain: 'foreign-'.Uuid::uuid7()->toString().'.com',
            createdAt: new \DateTimeImmutable(),
        );
        $foreignDomain->popEvents();
        $em->persist($foreignDomain);

        $foreignSenderId = Uuid::uuid7();
        $foreignSender = new KnownSender(
            id: $foreignSenderId,
            monitoredDomain: $foreignDomain,
            sourceIp: '9.9.9.9',
            firstSeenAt: new \DateTimeImmutable('2026-01-01'),
            lastSeenAt: new \DateTimeImmutable('2026-05-01'),
            totalMessages: 10,
            passRate: 50.0,
        );
        $em->persist($foreignSender);

        $em->flush();

        $client->loginUser($user);

        return [
            'client' => $client,
            'em' => $em,
            'team' => $team,
            'user' => $user,
            'domain' => $domain,
            'senderId' => $senderId,
            'foreignSenderId' => $foreignSenderId,
        ];
    }

    /**
     * Mirror of AlertActionsTest::csrfToken — bootstraps a session and
     * writes a derandomized token into it. Symfony's token manager
     * leaves unrandomized strings unchanged through `derandomize`, so a
     * raw hex value submitted as `_csrf_token` matches the stored value.
     */
    private function csrfToken(KernelBrowser $client, string $id, string $warmupPath): string
    {
        $client->request('GET', $warmupPath);

        $cookie = $client->getCookieJar()->get('MOCKSESSID') ?? $client->getCookieJar()->get('PHPSESSID');
        self::assertNotNull($cookie, 'Session cookie not set after warm-up GET.');

        $token = bin2hex(random_bytes(16));

        $factory = self::getContainer()->get('session.factory');
        assert($factory instanceof \Symfony\Component\HttpFoundation\Session\SessionFactoryInterface);

        $session = $factory->createSession();
        $session->setId($cookie->getValue());
        $session->start();
        $session->set('_csrf/'.$id, $token);
        $session->save();

        return $token;
    }

    private function inventoryPath(MonitoredDomain $domain): string
    {
        return '/app/domains/'.$domain->id->toString().'/senders';
    }

    public function testInventoryPageRendersCheckboxesAndNoteButtons(): void
    {
        $data = $this->bootClientWithSender();

        $data['client']->request('GET', $this->inventoryPath($data['domain']));

        self::assertResponseIsSuccessful();
        $content = $data['client']->getResponse()->getContent();
        self::assertIsString($content);
        self::assertStringContainsString('name="senderIds[]"', $content);
        self::assertStringContainsString('Authorize selected', $content);
        self::assertStringContainsString('Mark unknown selected', $content);
        self::assertStringContainsString('note-dialog-'.$data['senderId']->toString(), $content);
    }

    public function testAuditLineRendersWhenUpdatedAtIsSet(): void
    {
        $data = $this->bootClientWithSender();
        $sender = $data['em']->find(KnownSender::class, $data['senderId']);
        self::assertNotNull($sender);
        $sender->authorize($data['user'], new \DateTimeImmutable('2026-05-22 10:00:00'));
        $data['em']->flush();

        $data['client']->request('GET', $this->inventoryPath($data['domain']));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Last changed by '.$data['user']->email);
    }

    public function testAuditLineFallsBackToSystemWhenUserNull(): void
    {
        $data = $this->bootClientWithSender();
        $sender = $data['em']->find(KnownSender::class, $data['senderId']);
        self::assertNotNull($sender);
        // Simulate ON DELETE SET NULL — updatedAt set but user later cleared.
        $sender->isAuthorized = true;
        $sender->updatedAt = new \DateTimeImmutable('2026-05-22 10:00:00');
        $sender->updatedByUser = null;
        $data['em']->flush();

        $data['client']->request('GET', $this->inventoryPath($data['domain']));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Last changed by system');
    }

    public function testAuthorizeWithoutCsrfReturns403(): void
    {
        $data = $this->bootClientWithSender();

        $data['client']->request('POST', $this->inventoryPath($data['domain']).'/'.$data['senderId']->toString().'/authorize');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAuthorizeHappyPathSetsAuditFieldsAndRedirects(): void
    {
        $data = $this->bootClientWithSender();
        $token = $this->csrfToken($data['client'], 'sender_action', $this->inventoryPath($data['domain']));

        $data['client']->request('POST', $this->inventoryPath($data['domain']).'/'.$data['senderId']->toString().'/authorize', [
            '_csrf_token' => $token,
        ]);

        self::assertResponseRedirects($this->inventoryPath($data['domain']));

        $data['em']->clear();
        $sender = $data['em']->find(KnownSender::class, $data['senderId']);
        self::assertNotNull($sender);
        self::assertTrue($sender->isAuthorized);
        self::assertNotNull($sender->updatedAt);
        self::assertNotNull($sender->updatedByUser);
        self::assertSame($data['user']->id->toString(), $sender->updatedByUser->id->toString());
    }

    public function testAuthorizeUnknownSenderReturns404(): void
    {
        $data = $this->bootClientWithSender();
        $token = $this->csrfToken($data['client'], 'sender_action', $this->inventoryPath($data['domain']));

        $data['client']->request('POST', $this->inventoryPath($data['domain']).'/'.Uuid::uuid7()->toString().'/authorize', [
            '_csrf_token' => $token,
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testAuthorizeCrossTenantSenderReturns404(): void
    {
        $data = $this->bootClientWithSender();
        $token = $this->csrfToken($data['client'], 'sender_action', $this->inventoryPath($data['domain']));

        $data['client']->request('POST', $this->inventoryPath($data['domain']).'/'.$data['foreignSenderId']->toString().'/authorize', [
            '_csrf_token' => $token,
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testRevokeWithoutCsrfReturns403(): void
    {
        $data = $this->bootClientWithSender();

        $data['client']->request('POST', $this->inventoryPath($data['domain']).'/'.$data['senderId']->toString().'/revoke');

        self::assertResponseStatusCodeSame(403);
    }

    public function testRevokeHappyPathClearsAuthorizedAndRecordsAudit(): void
    {
        $data = $this->bootClientWithSender();
        $sender = $data['em']->find(KnownSender::class, $data['senderId']);
        self::assertNotNull($sender);
        $sender->isAuthorized = true;
        $data['em']->flush();

        $token = $this->csrfToken($data['client'], 'sender_action', $this->inventoryPath($data['domain']));

        $data['client']->request('POST', $this->inventoryPath($data['domain']).'/'.$data['senderId']->toString().'/revoke', [
            '_csrf_token' => $token,
        ]);

        self::assertResponseRedirects($this->inventoryPath($data['domain']));

        $data['em']->clear();
        $reloaded = $data['em']->find(KnownSender::class, $data['senderId']);
        self::assertNotNull($reloaded);
        self::assertFalse($reloaded->isAuthorized);
        self::assertNotNull($reloaded->updatedAt);
        self::assertNotNull($reloaded->updatedByUser);
    }

    public function testRevokeCrossTenantSenderReturns404(): void
    {
        $data = $this->bootClientWithSender();
        $token = $this->csrfToken($data['client'], 'sender_action', $this->inventoryPath($data['domain']));

        $data['client']->request('POST', $this->inventoryPath($data['domain']).'/'.$data['foreignSenderId']->toString().'/revoke', [
            '_csrf_token' => $token,
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testBulkWithoutCsrfReturns403(): void
    {
        $data = $this->bootClientWithSender();

        $data['client']->request('POST', $this->inventoryPath($data['domain']).'/bulk', [
            'action' => 'authorize',
            'senderIds' => [$data['senderId']->toString()],
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testBulkAuthorizeHappyPath(): void
    {
        $data = $this->bootClientWithSender();
        $token = $this->csrfToken($data['client'], 'bulk_sender_action', $this->inventoryPath($data['domain']));

        $data['client']->request('POST', $this->inventoryPath($data['domain']).'/bulk', [
            'action' => 'authorize',
            'senderIds' => [$data['senderId']->toString()],
            '_csrf_token' => $token,
        ]);

        self::assertResponseRedirects($this->inventoryPath($data['domain']));

        $data['em']->clear();
        $sender = $data['em']->find(KnownSender::class, $data['senderId']);
        self::assertNotNull($sender);
        self::assertTrue($sender->isAuthorized);
        self::assertNotNull($sender->updatedAt);
    }

    public function testBulkMarkUnknownHappyPath(): void
    {
        $data = $this->bootClientWithSender();
        $sender = $data['em']->find(KnownSender::class, $data['senderId']);
        self::assertNotNull($sender);
        $sender->isAuthorized = true;
        $data['em']->flush();

        $token = $this->csrfToken($data['client'], 'bulk_sender_action', $this->inventoryPath($data['domain']));

        $data['client']->request('POST', $this->inventoryPath($data['domain']).'/bulk', [
            'action' => 'mark_unknown',
            'senderIds' => [$data['senderId']->toString()],
            '_csrf_token' => $token,
        ]);

        self::assertResponseRedirects($this->inventoryPath($data['domain']));

        $data['em']->clear();
        $reloaded = $data['em']->find(KnownSender::class, $data['senderId']);
        self::assertNotNull($reloaded);
        self::assertFalse($reloaded->isAuthorized);
        self::assertNotNull($reloaded->updatedAt);
    }

    public function testBulkEmptySelectionIsNoOpRedirect(): void
    {
        $data = $this->bootClientWithSender();
        $token = $this->csrfToken($data['client'], 'bulk_sender_action', $this->inventoryPath($data['domain']));

        $data['client']->request('POST', $this->inventoryPath($data['domain']).'/bulk', [
            'action' => 'authorize',
            'senderIds' => [],
            '_csrf_token' => $token,
        ]);

        self::assertResponseRedirects($this->inventoryPath($data['domain']));

        $data['em']->clear();
        $sender = $data['em']->find(KnownSender::class, $data['senderId']);
        self::assertNotNull($sender);
        self::assertFalse($sender->isAuthorized);
    }

    public function testBulkSilentlySkipsCrossTenantSenderIds(): void
    {
        $data = $this->bootClientWithSender();
        $token = $this->csrfToken($data['client'], 'bulk_sender_action', $this->inventoryPath($data['domain']));

        $data['client']->request('POST', $this->inventoryPath($data['domain']).'/bulk', [
            'action' => 'authorize',
            'senderIds' => [
                $data['senderId']->toString(),
                $data['foreignSenderId']->toString(),
            ],
            '_csrf_token' => $token,
        ]);

        self::assertResponseRedirects($this->inventoryPath($data['domain']));

        $data['em']->clear();
        $own = $data['em']->find(KnownSender::class, $data['senderId']);
        $foreign = $data['em']->find(KnownSender::class, $data['foreignSenderId']);
        self::assertNotNull($own);
        self::assertNotNull($foreign);
        self::assertTrue($own->isAuthorized);
        self::assertFalse($foreign->isAuthorized);
    }

    public function testBulkUnknownActionReturns404(): void
    {
        $data = $this->bootClientWithSender();
        $token = $this->csrfToken($data['client'], 'bulk_sender_action', $this->inventoryPath($data['domain']));

        $data['client']->request('POST', $this->inventoryPath($data['domain']).'/bulk', [
            'action' => 'delete_everything',
            'senderIds' => [$data['senderId']->toString()],
            '_csrf_token' => $token,
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testNoteWithoutCsrfReturns403(): void
    {
        $data = $this->bootClientWithSender();

        $data['client']->request('POST', $this->inventoryPath($data['domain']).'/'.$data['senderId']->toString().'/note', [
            'note' => 'hello',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testNoteHappyPathPersistsAndRedirects(): void
    {
        $data = $this->bootClientWithSender();
        $token = $this->csrfToken($data['client'], 'sender_note', $this->inventoryPath($data['domain']));

        $data['client']->request('POST', $this->inventoryPath($data['domain']).'/'.$data['senderId']->toString().'/note', [
            'note' => 'Mailchimp marketing IP — DKIM set up 2026-04-12.',
            '_csrf_token' => $token,
        ]);

        self::assertResponseRedirects($this->inventoryPath($data['domain']));

        $data['em']->clear();
        $sender = $data['em']->find(KnownSender::class, $data['senderId']);
        self::assertNotNull($sender);
        self::assertSame('Mailchimp marketing IP — DKIM set up 2026-04-12.', $sender->notes);
        self::assertNotNull($sender->updatedAt);
        self::assertNotNull($sender->updatedByUser);
    }

    public function testNoteEmptyStringNormalizesToNull(): void
    {
        $data = $this->bootClientWithSender();
        $sender = $data['em']->find(KnownSender::class, $data['senderId']);
        self::assertNotNull($sender);
        $sender->notes = 'existing';
        $data['em']->flush();

        $token = $this->csrfToken($data['client'], 'sender_note', $this->inventoryPath($data['domain']));

        $data['client']->request('POST', $this->inventoryPath($data['domain']).'/'.$data['senderId']->toString().'/note', [
            'note' => '',
            '_csrf_token' => $token,
        ]);

        self::assertResponseRedirects($this->inventoryPath($data['domain']));

        $data['em']->clear();
        $reloaded = $data['em']->find(KnownSender::class, $data['senderId']);
        self::assertNotNull($reloaded);
        self::assertNull($reloaded->notes);
    }

    public function testNoteTruncatesAt10000Chars(): void
    {
        $data = $this->bootClientWithSender();
        $token = $this->csrfToken($data['client'], 'sender_note', $this->inventoryPath($data['domain']));

        $longNote = str_repeat('a', 15000);

        $data['client']->request('POST', $this->inventoryPath($data['domain']).'/'.$data['senderId']->toString().'/note', [
            'note' => $longNote,
            '_csrf_token' => $token,
        ]);

        self::assertResponseRedirects($this->inventoryPath($data['domain']));

        $data['em']->clear();
        $sender = $data['em']->find(KnownSender::class, $data['senderId']);
        self::assertNotNull($sender);
        self::assertNotNull($sender->notes);
        self::assertSame(10000, mb_strlen($sender->notes));
    }

    public function testNoteCrossTenantSenderReturns404(): void
    {
        $data = $this->bootClientWithSender();
        $token = $this->csrfToken($data['client'], 'sender_note', $this->inventoryPath($data['domain']));

        $data['client']->request('POST', $this->inventoryPath($data['domain']).'/'.$data['foreignSenderId']->toString().'/note', [
            'note' => 'cross-tenant write attempt',
            '_csrf_token' => $token,
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testSenderActionsRequireAuthentication(): void
    {
        $client = self::createClient();

        $client->request('POST', '/app/domains/'.Uuid::uuid7()->toString().'/senders/'.Uuid::uuid7()->toString().'/authorize');

        // Anonymous → redirect to login.
        self::assertResponseRedirects();
    }
}
