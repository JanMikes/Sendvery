<?php

declare(strict_types=1);

namespace App\Tests\Fixtures;

use App\Entity\KnownSender;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Entity\TeamMembership;
use App\Entity\User;
use App\Value\SenderReviewState;
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

    /**
     * Attach a `known_sender` row to an existing domain, for the cases the
     * fluent {@see PersonaBuilder::withKnownSender()} cannot cover (extra
     * domains added after the persona was built).
     *
     * `reviewState` mirrors how the columns encode the three states — see
     * {@see SenderReviewState}.
     */
    public function addKnownSender(
        MonitoredDomain $domain,
        string $sourceIp,
        int $totalMessages = 100,
        float $passRate = 100.0,
        ?string $organization = null,
        ?string $hostname = null,
        SenderReviewState $reviewState = SenderReviewState::NeedsReview,
        ?User $decidedBy = null,
    ): KnownSender {
        $sender = new KnownSender(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            sourceIp: $sourceIp,
            firstSeenAt: new \DateTimeImmutable('-30 days'),
            lastSeenAt: new \DateTimeImmutable('-1 day'),
            totalMessages: $totalMessages,
            passRate: $passRate,
            hostname: $hostname,
            organization: $organization,
            isAuthorized: SenderReviewState::Authorized === $reviewState,
        );

        if (SenderReviewState::NotAuthorized === $reviewState && null !== $decidedBy) {
            $sender->markUnknown($decidedBy, new \DateTimeImmutable('-2 days'));
        }

        $this->entityManager->persist($sender);
        $this->entityManager->flush();

        return $sender;
    }

    public function nonExistentUuid(): UuidInterface
    {
        return Uuid::uuid7();
    }
}
