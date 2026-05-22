<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\DomainOwnershipInquiry;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Entity\TeamMembership;
use App\Entity\User;
use App\Exceptions\DomainNotTaken;
use App\Exceptions\InquiryRateLimited;
use App\Message\CreateDomainOwnershipInquiry;
use App\MessageHandler\CreateDomainOwnershipInquiryHandler;
use App\Services\IdentityProvider;
use App\Tests\IntegrationTestCase;
use App\Value\TeamRole;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class CreateDomainOwnershipInquiryHandlerTest extends IntegrationTestCase
{
    private EntityManagerInterface $em;
    private CreateDomainOwnershipInquiryHandler $handler;
    private IdentityProvider $identityProvider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em = $this->getService(EntityManagerInterface::class);
        $this->handler = $this->getService(CreateDomainOwnershipInquiryHandler::class);
        $this->identityProvider = $this->getService(IdentityProvider::class);
    }

    public function testRecordsInquiryWhenDomainIsTakenByAnotherTeam(): void
    {
        [$ownerTeam, $_ownerUser] = $this->seedTeamWithUser('current-owner-'.bin2hex(random_bytes(4)).'@example.com');
        $this->createDomain($ownerTeam, 'inquiry-test-'.bin2hex(random_bytes(4)).'.com');
        [$inquirerTeam, $inquirerUser] = $this->seedTeamWithUser('inquirer-'.bin2hex(random_bytes(4)).'@example.com');
        $this->em->flush();

        $domain = 'inquiry-test-some.com';
        $this->createDomain($ownerTeam, $domain);
        $this->em->flush();

        ($this->handler)(new CreateDomainOwnershipInquiry(
            inquiryId: $this->identityProvider->nextIdentity(),
            domain: $domain,
            inquiringUserId: $inquirerUser->id,
            inquiringTeamId: $inquirerTeam->id,
        ));

        $this->em->flush();
        $this->em->clear();

        $inquiry = $this->em->getRepository(DomainOwnershipInquiry::class)
            ->findOneBy(['domain' => $domain]);
        self::assertNotNull($inquiry);
        self::assertSame($inquirerUser->id->toString(), $inquiry->inquiringUser->id->toString());
        self::assertSame($ownerTeam->id->toString(), $inquiry->currentOwnerTeam->id->toString());
    }

    public function testRateLimitsRepeatedInquiriesForSameUserAndDomain(): void
    {
        [$ownerTeam, $_o] = $this->seedTeamWithUser('o-rate@example.com');
        [$inquirerTeam, $inquirerUser] = $this->seedTeamWithUser('i-rate@example.com');
        $domain = 'rate-limit-'.bin2hex(random_bytes(4)).'.com';
        $this->createDomain($ownerTeam, $domain);
        $this->em->flush();

        ($this->handler)(new CreateDomainOwnershipInquiry(
            inquiryId: $this->identityProvider->nextIdentity(),
            domain: $domain,
            inquiringUserId: $inquirerUser->id,
            inquiringTeamId: $inquirerTeam->id,
        ));
        $this->em->flush();

        $this->expectException(InquiryRateLimited::class);

        ($this->handler)(new CreateDomainOwnershipInquiry(
            inquiryId: $this->identityProvider->nextIdentity(),
            domain: $domain,
            inquiringUserId: $inquirerUser->id,
            inquiringTeamId: $inquirerTeam->id,
        ));
    }

    public function testRefusesWhenDomainIsNotActuallyTaken(): void
    {
        [$inquirerTeam, $inquirerUser] = $this->seedTeamWithUser('i-orphan@example.com');
        $this->em->flush();

        $this->expectException(DomainNotTaken::class);

        ($this->handler)(new CreateDomainOwnershipInquiry(
            inquiryId: $this->identityProvider->nextIdentity(),
            domain: 'never-monitored-'.bin2hex(random_bytes(4)).'.com',
            inquiringUserId: $inquirerUser->id,
            inquiringTeamId: $inquirerTeam->id,
        ));
    }

    /** @return array{0: Team, 1: User} */
    private function seedTeamWithUser(string $email): array
    {
        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Inquiry Test',
            slug: 'inquiry-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $this->em->persist($team);

        $user = new User(
            id: $this->identityProvider->nextIdentity(),
            email: $email,
            createdAt: new \DateTimeImmutable(),
        );
        $this->em->persist($user);

        $this->em->persist(new TeamMembership(
            id: $this->identityProvider->nextIdentity(),
            user: $user,
            team: $team,
            role: TeamRole::Owner,
            joinedAt: new \DateTimeImmutable(),
        ));

        return [$team, $user];
    }

    private function createDomain(Team $team, string $name): MonitoredDomain
    {
        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: $name,
            createdAt: new \DateTimeImmutable(),
            dmarcVerifiedAt: new \DateTimeImmutable('-1 day'),
        );
        $this->em->persist($domain);

        return $domain;
    }
}
