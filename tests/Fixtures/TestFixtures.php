<?php

declare(strict_types=1);

namespace App\Tests\Fixtures;

use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Entity\TeamMembership;
use App\Entity\User;
use App\Value\TeamRole;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

/**
 * Centralised test data builder used by the controller smoke / scenario
 * tests. Hides the boilerplate of persisting a User + Team + Membership (+
 * optional Domain) and exposes a fluent {@see persona()} builder for
 * varied scenarios — anonymous vs onboarded, owner vs admin vs member,
 * free vs paid plan, with or without a domain.
 */
final class TestFixtures
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public static function fromContainer(ContainerInterface $container): self
    {
        $em = $container->get(EntityManagerInterface::class); // @phpstan-ignore symfonyContainer.privateService
        assert($em instanceof EntityManagerInterface);

        return new self($em);
    }

    public function persona(): PersonaBuilder
    {
        return new PersonaBuilder($this->entityManager);
    }

    /**
     * Onboarded owner with a domain — the default "happy path" persona used
     * by most /app/* smoke tests.
     */
    public function onboardedOwner(): Persona
    {
        return $this->persona()->build();
    }

    public function addExtraTeammate(Team $team, TeamRole $role = TeamRole::Member): User
    {
        $userId = Uuid::uuid7();
        $user = new User(
            id: $userId,
            email: 'teammate-'.$userId->toString().'@example.com',
            createdAt: new \DateTimeImmutable(),
            onboardingCompletedAt: new \DateTimeImmutable(),
        );
        $user->popEvents();
        $this->entityManager->persist($user);

        $this->entityManager->persist(new TeamMembership(
            id: Uuid::uuid7(),
            user: $user,
            team: $team,
            role: $role,
            joinedAt: new \DateTimeImmutable(),
        ));
        $this->entityManager->flush();

        return $user;
    }

    public function addExtraDomain(Team $team, ?string $name = null): MonitoredDomain
    {
        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: ($name ?? 'extra-'.Uuid::uuid7()->toString()).'.example',
            createdAt: new \DateTimeImmutable(),
        );
        $domain->popEvents();
        $this->entityManager->persist($domain);
        $this->entityManager->flush();

        return $domain;
    }

    public function nonExistentUuid(): UuidInterface
    {
        return Uuid::uuid7();
    }
}
