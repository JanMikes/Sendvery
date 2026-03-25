<?php

declare(strict_types=1);

namespace App\Tests\Integration\Query;

use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Query\GetDomainPassRateTrend;
use App\Tests\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class GetDomainPassRateTrendTest extends IntegrationTestCase
{
    public function testReturnsTrendForDomain(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetDomainPassRateTrend::class);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Trend Test',
            slug: 'trend-test-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);

        $domainId = Uuid::uuid7();
        $domain = new MonitoredDomain(
            id: $domainId,
            team: $team,
            domain: 'trend-test.com',
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($domain);
        $em->flush();

        $results = $query->forDomain($domainId->toString(), days: 7);

        self::assertCount(8, $results); // 7 days + today
        foreach ($results as $result) {
            self::assertNotEmpty($result->date);
            self::assertSame(0, $result->passCount);
            self::assertSame(0, $result->failCount);
        }
    }

    public function testReturnsTrendForTeam(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetDomainPassRateTrend::class);

        $teamId = Uuid::uuid7();
        $team = new Team(
            id: $teamId,
            name: 'Team Trend',
            slug: 'team-trend-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);
        $em->flush();

        $results = $query->forTeam($teamId->toString(), days: 3);

        self::assertCount(4, $results); // 3 days + today
    }
}
