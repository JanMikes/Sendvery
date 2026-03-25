<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\User;
use App\Exceptions\UserNotFound;
use App\Repository\UserRepository;
use App\Tests\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class UserRepositoryTest extends IntegrationTestCase
{
    public function testGetReturnsUser(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $id = Uuid::uuid7();

        $user = new User(
            id: $id,
            email: 'get-'.$id->toString().'@test.com',
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($user);
        $em->flush();

        $repository = $this->getService(UserRepository::class);
        $found = $repository->get($id);

        self::assertSame($id->toString(), $found->id->toString());
    }

    public function testGetThrowsWhenNotFound(): void
    {
        $repository = $this->getService(UserRepository::class);

        $this->expectException(UserNotFound::class);
        $repository->get(Uuid::uuid7());
    }

    public function testFindByEmailReturnsUser(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $id = Uuid::uuid7();
        $email = 'find-'.$id->toString().'@test.com';

        $user = new User(
            id: $id,
            email: $email,
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($user);
        $em->flush();

        $repository = $this->getService(UserRepository::class);
        $found = $repository->findByEmail($email);

        self::assertNotNull($found);
        self::assertSame($id->toString(), $found->id->toString());
    }

    public function testFindByEmailReturnsNullWhenNotFound(): void
    {
        $repository = $this->getService(UserRepository::class);

        self::assertNull($repository->findByEmail('nonexistent@test.com'));
    }
}
