<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\BetaSignup;
use App\Repository\BetaSignupRepository;
use App\Tests\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class BetaSignupRepositoryTest extends IntegrationTestCase
{
    public function testFindByToken(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $repository = $this->getService(BetaSignupRepository::class);

        $signup = new BetaSignup(
            id: Uuid::uuid7(),
            email: 'token-test-'.Uuid::uuid7()->toString().'@example.com',
            domainCount: null,
            painPoint: null,
            source: 'test',
            signedUpAt: new \DateTimeImmutable(),
            confirmationToken: 'findabletoken123',
        );
        $signup->popEvents();
        $em->persist($signup);
        $em->flush();

        $found = $repository->findByToken('findabletoken123');
        self::assertNotNull($found);
        self::assertSame($signup->id->toString(), $found->id->toString());
    }

    public function testFindByTokenReturnsNullForUnknownToken(): void
    {
        $repository = $this->getService(BetaSignupRepository::class);

        $found = $repository->findByToken('nonexistent');
        self::assertNull($found);
    }

    public function testFindByEmail(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $repository = $this->getService(BetaSignupRepository::class);

        $email = 'email-test-'.Uuid::uuid7()->toString().'@example.com';
        $signup = new BetaSignup(
            id: Uuid::uuid7(),
            email: $email,
            domainCount: null,
            painPoint: null,
            source: 'test',
            signedUpAt: new \DateTimeImmutable(),
            confirmationToken: 'emailtoken'.Uuid::uuid7()->toString(),
        );
        $signup->popEvents();
        $em->persist($signup);
        $em->flush();

        $found = $repository->findByEmail($email);
        self::assertNotNull($found);
        self::assertSame($email, $found->email);
    }

    public function testFindByEmailReturnsNullForUnknownEmail(): void
    {
        $repository = $this->getService(BetaSignupRepository::class);

        $found = $repository->findByEmail('nonexistent@example.com');
        self::assertNull($found);
    }
}
