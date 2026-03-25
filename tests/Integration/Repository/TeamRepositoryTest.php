<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Team;
use App\Exceptions\TeamNotFound;
use App\Repository\TeamRepository;
use App\Tests\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class TeamRepositoryTest extends IntegrationTestCase
{
    public function testGetReturnsTeam(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $id = Uuid::uuid7();

        $team = new Team(
            id: $id,
            name: 'Test Team',
            slug: 'test-team-' . $id->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);
        $em->flush();

        $repository = $this->getService(TeamRepository::class);
        $found = $repository->get($id);

        self::assertSame($id->toString(), $found->id->toString());
        self::assertSame('Test Team', $found->name);
    }

    public function testGetThrowsWhenNotFound(): void
    {
        $repository = $this->getService(TeamRepository::class);

        $this->expectException(TeamNotFound::class);
        $repository->get(Uuid::uuid7());
    }

    public function testFindBySlugReturnsTeam(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $id = Uuid::uuid7();
        $slug = 'unique-slug-' . $id->toString();

        $team = new Team(
            id: $id,
            name: 'Slug Team',
            slug: $slug,
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);
        $em->flush();

        $repository = $this->getService(TeamRepository::class);
        $found = $repository->findBySlug($slug);

        self::assertNotNull($found);
        self::assertSame($id->toString(), $found->id->toString());
    }

    public function testFindBySlugReturnsNullWhenNotFound(): void
    {
        $repository = $this->getService(TeamRepository::class);

        self::assertNull($repository->findBySlug('nonexistent-slug'));
    }
}
