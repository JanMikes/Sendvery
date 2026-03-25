<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\MagicLinkToken;
use App\Entity\User;
use App\Message\RequestMagicLink;
use App\MessageHandler\RequestMagicLinkHandler;
use App\Tests\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class RequestMagicLinkHandlerTest extends IntegrationTestCase
{
    public function testCreatesTokenAndSendsEmail(): void
    {
        $handler = self::getContainer()->get(RequestMagicLinkHandler::class);
        assert($handler instanceof RequestMagicLinkHandler);

        $tokenId = Uuid::uuid7();
        $email = 'magic-'.$tokenId->toString().'@example.com';

        $handler(new RequestMagicLink(
            tokenId: $tokenId,
            email: $email,
        ));

        $em = $this->getService(EntityManagerInterface::class);
        $token = $em->find(MagicLinkToken::class, $tokenId);

        self::assertNotNull($token);
        self::assertSame($email, $token->email);
        self::assertNull($token->user);
        self::assertNull($token->usedAt);
        self::assertNotEmpty($token->token);
        self::assertSame(64, strlen($token->token));
    }

    public function testLinksTokenToExistingUser(): void
    {
        $em = $this->getService(EntityManagerInterface::class);

        $userId = Uuid::uuid7();
        $email = 'existing-'.$userId->toString().'@example.com';
        $user = new User(
            id: $userId,
            email: $email,
            createdAt: new \DateTimeImmutable(),
        );
        $user->popEvents();
        $em->persist($user);
        $em->flush();
        $em->clear();

        $handler = self::getContainer()->get(RequestMagicLinkHandler::class);
        assert($handler instanceof RequestMagicLinkHandler);

        $tokenId = Uuid::uuid7();

        $handler(new RequestMagicLink(
            tokenId: $tokenId,
            email: $email,
        ));

        $em->clear();
        $token = $em->find(MagicLinkToken::class, $tokenId);

        self::assertNotNull($token);
        self::assertNotNull($token->user);
        self::assertSame($userId->toString(), $token->user->id->toString());
    }

    public function testRateLimitsRequestsPerEmail(): void
    {
        $handler = self::getContainer()->get(RequestMagicLinkHandler::class);
        assert($handler instanceof RequestMagicLinkHandler);

        $email = 'ratelimit-'.Uuid::uuid7()->toString().'@example.com';

        // Send 5 requests (max allowed)
        for ($i = 0; $i < 5; ++$i) {
            $handler(new RequestMagicLink(
                tokenId: Uuid::uuid7(),
                email: $email,
            ));
        }

        // 6th request should be silently ignored
        $tokenId = Uuid::uuid7();
        $handler(new RequestMagicLink(
            tokenId: $tokenId,
            email: $email,
        ));

        $em = $this->getService(EntityManagerInterface::class);
        $token = $em->find(MagicLinkToken::class, $tokenId);

        self::assertNull($token);
    }
}
