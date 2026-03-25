<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Tests\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class CheckAllDomainsDnsCommandTest extends IntegrationTestCase
{
    #[Test]
    public function runsWithNoDomains(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);
        $command = $application->find('sendvery:dns:check-all');
        $tester = new CommandTester($command);

        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
    }

    #[Test]
    public function dispatchesChecksForExistingDomains(): void
    {
        $em = $this->getService(EntityManagerInterface::class);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'DNS CMD Team',
            slug: 'dns-cmd-team-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $team->popEvents();
        $em->persist($team);

        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'dns-cmd-test.com',
            createdAt: new \DateTimeImmutable(),
        );
        $domain->popEvents();
        $em->persist($domain);
        $em->flush();
        $em->clear();

        $application = new Application(self::$kernel);
        $command = $application->find('sendvery:dns:check-all');
        $tester = new CommandTester($command);

        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Dispatched DNS checks for', $tester->getDisplay());
    }
}
