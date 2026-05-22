<?php

declare(strict_types=1);

namespace App\Tests\Integration\Services\Reports;

use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Services\Reports\DmarcReportRouter;
use App\Tests\IntegrationTestCase;
use App\Value\Reports\QuarantineReason;
use App\Value\Reports\RoutingKind;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class DmarcReportRouterTest extends IntegrationTestCase
{
    private EntityManagerInterface $em;
    private DmarcReportRouter $router;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em = $this->getService(EntityManagerInterface::class);
        $this->router = $this->getService(DmarcReportRouter::class);
    }

    public function testRoutesToVerifiedDomain(): void
    {
        $team = $this->createTeam();
        $verified = $this->createDomain($team, 'router-verified.com', verified: true);
        $this->em->flush();
        $this->em->clear();

        $decision = $this->router->route('router-verified.com');

        self::assertSame(RoutingKind::Routed, $decision->kind);
        self::assertNotNull($decision->domain);
        self::assertSame($verified->id->toString(), $decision->domain->id->toString());
    }

    public function testIsCaseInsensitive(): void
    {
        $team = $this->createTeam();
        $this->createDomain($team, 'router-case.com', verified: true);
        $this->em->flush();
        $this->em->clear();

        $decision = $this->router->route('  ROUTER-CASE.COM  ');

        self::assertSame(RoutingKind::Routed, $decision->kind);
    }

    public function testQuarantinesForUnverifiedDomain(): void
    {
        $team = $this->createTeam();
        $this->createDomain($team, 'router-unverified.com', verified: false);
        $this->em->flush();
        $this->em->clear();

        $decision = $this->router->route('router-unverified.com');

        self::assertSame(RoutingKind::Quarantined, $decision->kind);
        self::assertSame(QuarantineReason::UnverifiedDomain, $decision->quarantineReason);
    }

    public function testQuarantinesForUnknownDomain(): void
    {
        $decision = $this->router->route('router-unknown-'.bin2hex(random_bytes(4)).'.com');

        self::assertSame(RoutingKind::Quarantined, $decision->kind);
        self::assertSame(QuarantineReason::UnknownDomain, $decision->quarantineReason);
    }

    public function testIgnoresEmptyDomain(): void
    {
        $decision = $this->router->route('');

        self::assertSame(RoutingKind::Ignored, $decision->kind);
    }

    private function createTeam(): Team
    {
        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Router Test',
            slug: 'router-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $this->em->persist($team);

        return $team;
    }

    private function createDomain(Team $team, string $name, bool $verified): MonitoredDomain
    {
        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: $name,
            createdAt: new \DateTimeImmutable(),
            dmarcVerifiedAt: $verified ? new \DateTimeImmutable('-1 day') : null,
        );
        $this->em->persist($domain);

        return $domain;
    }
}
