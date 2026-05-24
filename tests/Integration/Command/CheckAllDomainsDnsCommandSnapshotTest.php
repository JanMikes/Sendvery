<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\DomainHealthSnapshot;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Tests\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class CheckAllDomainsDnsCommandSnapshotTest extends IntegrationTestCase
{
    #[Test]
    public function writesOneSnapshotPerDomainAfterDnsChecksRun(): void
    {
        $em = $this->getService(EntityManagerInterface::class);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Snapshot CMD Team',
            slug: 'snapshot-cmd-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $team->popEvents();
        $em->persist($team);

        foreach (['snap-cmd-one.example', 'snap-cmd-two.example'] as $name) {
            $domain = new MonitoredDomain(
                id: Uuid::uuid7(),
                team: $team,
                domain: $name,
                createdAt: new \DateTimeImmutable(),
            );
            $domain->popEvents();
            $em->persist($domain);
        }
        $em->flush();
        $em->clear();

        $this->runCommand();

        $snapshotCount = $em->getRepository(DomainHealthSnapshot::class)->count([]);
        self::assertSame(2, $snapshotCount, 'Each monitored domain must produce exactly one snapshot row per run');
    }

    #[Test]
    public function writesZeroSnapshotsWhenNoDomainsExist(): void
    {
        $em = $this->getService(EntityManagerInterface::class);

        $this->runCommand();

        $snapshotCount = $em->getRepository(DomainHealthSnapshot::class)->count([]);
        self::assertSame(0, $snapshotCount);
    }

    private function runCommand(): void
    {
        assert(null !== self::$kernel);
        $application = new Application(self::$kernel);
        $command = $application->find('sendvery:dns:check-all');
        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
    }
}
