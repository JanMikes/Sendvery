<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\MagicLinkToken;
use App\Repository\MagicLinkTokenRepository;
use App\Tests\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class MagicLinkTokenRepositoryTest extends IntegrationTestCase
{
    public function testFindByTokenReturnsToken(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $repository = $this->getService(MagicLinkTokenRepository::class);

        $tokenString = bin2hex(random_bytes(32));
        $token = new MagicLinkToken(
            id: Uuid::uuid7(),
            email: 'test@example.com',
            token: $tokenString,
            expiresAt: new \DateTimeImmutable('+15 minutes'),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($token);
        $em->flush();

        $found = $repository->findByToken($tokenString);

        self::assertNotNull($found);
        self::assertSame($token->id->toString(), $found->id->toString());
    }

    public function testFindByTokenReturnsNullForUnknownToken(): void
    {
        $repository = $this->getService(MagicLinkTokenRepository::class);

        self::assertNull($repository->findByToken('nonexistent'));
    }

    public function testCountRecentByEmail(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $repository = $this->getService(MagicLinkTokenRepository::class);

        $email = 'count-'.Uuid::uuid7()->toString().'@example.com';
        $now = new \DateTimeImmutable();

        for ($i = 0; $i < 3; ++$i) {
            $token = new MagicLinkToken(
                id: Uuid::uuid7(),
                email: $email,
                token: bin2hex(random_bytes(32)),
                expiresAt: new \DateTimeImmutable('+15 minutes'),
                createdAt: $now,
            );
            $em->persist($token);
        }
        $em->flush();

        $count = $repository->countRecentByEmail($email, $now->modify('-1 hour'));

        self::assertSame(3, $count);
    }
}
