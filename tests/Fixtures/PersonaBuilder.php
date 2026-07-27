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
use Ramsey\Uuid\Uuid;

/**
 * Fluent builder for a fully-bootstrapped test persona: user + team +
 * membership + optional monitored domain. Wraps the boilerplate that used to
 * live inline in every controller test.
 */
final class PersonaBuilder
{
    private string $emailPrefix = 'persona';
    private TeamRole $role = TeamRole::Owner;
    private string $plan = 'free';
    private bool $completedOnboarding = true;
    private bool $withDomain = true;
    private string $domainName = 'test.example';
    private string $teamName = 'Test Team';

    /** @var list<array{sourceIp: string, totalMessages: int, passRate: float, organization: string|null, hostname: string|null, reviewState: SenderReviewState}> */
    private array $knownSenders = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function emailPrefix(string $prefix): self
    {
        $this->emailPrefix = $prefix;

        return $this;
    }

    public function role(TeamRole $role): self
    {
        $this->role = $role;

        return $this;
    }

    public function plan(string $plan): self
    {
        $this->plan = $plan;

        return $this;
    }

    public function notOnboarded(): self
    {
        $this->completedOnboarding = false;
        $this->withDomain = false;

        return $this;
    }

    public function withoutDomain(): self
    {
        $this->withDomain = false;

        return $this;
    }

    public function withDomain(string $name): self
    {
        $this->withDomain = true;
        $this->domainName = $name;

        return $this;
    }

    public function teamName(string $name): self
    {
        $this->teamName = $name;

        return $this;
    }

    /**
     * Queue a `known_sender` row for the persona's domain.
     *
     * `reviewState` mirrors how the columns actually encode the three states
     * (see {@see SenderReviewState}) so a test can say "this sender is awaiting
     * review" or "somebody rejected this one" without knowing that the
     * distinction lives in `updated_at`.
     */
    public function withKnownSender(
        string $sourceIp,
        int $totalMessages = 100,
        float $passRate = 100.0,
        ?string $organization = null,
        ?string $hostname = null,
        SenderReviewState $reviewState = SenderReviewState::NeedsReview,
    ): self {
        $this->knownSenders[] = [
            'sourceIp' => $sourceIp,
            'totalMessages' => $totalMessages,
            'passRate' => $passRate,
            'organization' => $organization,
            'hostname' => $hostname,
            'reviewState' => $reviewState,
        ];

        return $this;
    }

    public function build(): Persona
    {
        $userId = Uuid::uuid7();
        $user = new User(
            id: $userId,
            email: $this->emailPrefix.'-'.$userId->toString().'@example.com',
            createdAt: new \DateTimeImmutable(),
            onboardingCompletedAt: $this->completedOnboarding ? new \DateTimeImmutable() : null,
        );
        $user->popEvents();
        $this->entityManager->persist($user);

        $team = new Team(
            id: Uuid::uuid7(),
            name: $this->teamName,
            slug: $this->emailPrefix.'-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
            plan: $this->plan,
        );
        $team->popEvents();
        $this->entityManager->persist($team);

        $membership = new TeamMembership(
            id: Uuid::uuid7(),
            user: $user,
            team: $team,
            role: $this->role,
            joinedAt: new \DateTimeImmutable(),
        );
        $this->entityManager->persist($membership);

        $domain = null;
        if ($this->withDomain) {
            $domain = new MonitoredDomain(
                id: Uuid::uuid7(),
                team: $team,
                domain: Uuid::uuid7()->toString().'.'.$this->domainName,
                createdAt: new \DateTimeImmutable(),
            );
            $domain->popEvents();
            $this->entityManager->persist($domain);

            foreach ($this->knownSenders as $spec) {
                $sender = new KnownSender(
                    id: Uuid::uuid7(),
                    monitoredDomain: $domain,
                    sourceIp: $spec['sourceIp'],
                    firstSeenAt: new \DateTimeImmutable('-30 days'),
                    lastSeenAt: new \DateTimeImmutable('-1 day'),
                    totalMessages: $spec['totalMessages'],
                    passRate: $spec['passRate'],
                    hostname: $spec['hostname'],
                    organization: $spec['organization'],
                    isAuthorized: SenderReviewState::Authorized === $spec['reviewState'],
                );

                // NotAuthorized is "a human looked at it and said no", which the
                // schema records as is_authorized=false plus a decision stamp.
                if (SenderReviewState::NotAuthorized === $spec['reviewState']) {
                    $sender->markUnknown($user, new \DateTimeImmutable('-2 days'));
                }

                $this->entityManager->persist($sender);
            }
        }

        $this->entityManager->flush();

        return new Persona($user, $team, $membership, $domain);
    }
}
