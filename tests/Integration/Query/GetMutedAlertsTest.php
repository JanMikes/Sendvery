<?php

declare(strict_types=1);

namespace App\Tests\Integration\Query;

use App\Entity\MonitoredDomain;
use App\Entity\MutedAlert;
use App\Entity\Team;
use App\Query\GetMutedAlerts;
use App\Tests\IntegrationTestCase;
use App\Value\AlertType;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class GetMutedAlertsTest extends IntegrationTestCase
{
    public function testReturnsTeamMutesJoinedWithDomain(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetMutedAlerts::class);

        [$team, $domain] = $this->persistTeamAndDomain($em, 'muted-list');

        $muted = new MutedAlert(
            id: Uuid::uuid7(),
            team: $team,
            monitoredDomain: $domain,
            alertType: AlertType::FailureSpike,
            mutedAt: new \DateTimeImmutable(),
        );
        $em->persist($muted);
        $em->flush();

        $results = $query->forTeams([$team->id->toString()]);

        self::assertCount(1, $results);
        self::assertSame($domain->domain, $results[0]->domainName);
        self::assertSame(AlertType::FailureSpike->value, $results[0]->alertType);
    }

    public function testEmptyTeamIdsReturnsEmpty(): void
    {
        $query = $this->getService(GetMutedAlerts::class);

        self::assertSame([], $query->forTeams([]));
    }

    public function testReturnsEmptyForTeamWithNoMutes(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetMutedAlerts::class);

        [$team] = $this->persistTeamAndDomain($em, 'no-mutes');

        self::assertSame([], $query->forTeams([$team->id->toString()]));
    }

    public function testDoesNotLeakAcrossTeams(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetMutedAlerts::class);

        [$teamA, $domainA] = $this->persistTeamAndDomain($em, 'mute-a');
        [$teamB] = $this->persistTeamAndDomain($em, 'mute-b');

        $em->persist(new MutedAlert(
            id: Uuid::uuid7(),
            team: $teamA,
            monitoredDomain: $domainA,
            alertType: AlertType::FailureSpike,
            mutedAt: new \DateTimeImmutable(),
        ));
        $em->flush();

        self::assertCount(0, $query->forTeams([$teamB->id->toString()]));
        self::assertCount(1, $query->forTeams([$teamA->id->toString()]));
    }

    /**
     * @return array{Team, MonitoredDomain}
     */
    private function persistTeamAndDomain(EntityManagerInterface $em, string $slugPrefix): array
    {
        $team = new Team(
            id: Uuid::uuid7(),
            name: $slugPrefix,
            slug: $slugPrefix.'-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $team->popEvents();
        $em->persist($team);

        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: $slugPrefix.'-'.Uuid::uuid7()->toString().'.com',
            createdAt: new \DateTimeImmutable(),
        );
        $domain->popEvents();
        $em->persist($domain);
        $em->flush();

        return [$team, $domain];
    }
}
