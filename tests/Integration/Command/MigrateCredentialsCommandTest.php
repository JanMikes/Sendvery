<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Tests\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class MigrateCredentialsCommandTest extends IntegrationTestCase
{
    #[Test]
    public function runsWithNoConnections(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);
        $command = $application->find('sendvery:credentials:migrate');
        $tester = new CommandTester($command);

        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Migration complete', $tester->getDisplay());
    }
}
