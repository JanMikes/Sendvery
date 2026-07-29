<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\MagicLinkToken;
use App\Tests\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A magic_link_token row is written for every sign-in attempt — including
 * every address a signup-abuse bot submits — and nothing else ever deletes
 * them. The purge keeps the table from becoming a permanent log of every
 * email ever typed into the login form, while retaining a 30-day forensic
 * window of requested_ip / requested_user_agent.
 */
final class PurgeExpiredMagicLinkTokensCommandTest extends IntegrationTestCase
{
    #[Test]
    public function purgesTokensPastRetentionAndKeepsRecentOnes(): void
    {
        $em = $this->getService(EntityManagerInterface::class);

        $oldToken = $this->makeToken(new \DateTimeImmutable('-31 days'));
        $recentToken = $this->makeToken(new \DateTimeImmutable('-1 day'));
        $em->persist($oldToken);
        $em->persist($recentToken);
        $em->flush();

        $tester = $this->tester();
        $exit = $tester->execute([]);

        self::assertSame(0, $exit);
        $em->clear();

        self::assertNull($em->find(MagicLinkToken::class, $oldToken->id), 'A token past the 30-day retention window must be deleted — the table must not grow into a permanent log of every submitted email address.');
        self::assertNotNull($em->find(MagicLinkToken::class, $recentToken->id), 'A token inside the retention window must survive — it is the forensic trail for an ongoing abuse investigation.');
        self::assertStringContainsString('Purged 1 magic-link token(s)', $tester->getDisplay());
    }

    #[Test]
    public function reportsZeroWhenNothingToPurge(): void
    {
        $tester = $this->tester();
        $exit = $tester->execute([]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('No magic-link tokens to purge.', $tester->getDisplay());
    }

    private function makeToken(\DateTimeImmutable $createdAt): MagicLinkToken
    {
        return new MagicLinkToken(
            id: Uuid::uuid7(),
            email: 'purge-'.Uuid::uuid7()->toString().'@example.com',
            token: bin2hex(random_bytes(32)),
            expiresAt: $createdAt->modify('+15 minutes'),
            createdAt: $createdAt,
        );
    }

    private function tester(): CommandTester
    {
        $kernel = self::$kernel ?? self::bootKernel();
        $application = new Application($kernel);

        return new CommandTester($application->find('sendvery:auth:purge-magic-links'));
    }
}
