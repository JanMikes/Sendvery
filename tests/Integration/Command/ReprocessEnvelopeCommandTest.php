<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\ReceivedReportEmail;
use App\Services\IdentityProvider;
use App\Tests\IntegrationTestCase;
use App\Value\Reports\ReportSource;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class ReprocessEnvelopeCommandTest extends IntegrationTestCase
{
    private EntityManagerInterface $em;
    private IdentityProvider $identityProvider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em = $this->getService(EntityManagerInterface::class);
        $this->identityProvider = $this->getService(IdentityProvider::class);
    }

    public function testRedispatchesForFailedEnvelope(): void
    {
        $envelope = $this->makeEnvelope();
        $envelope->markFailed('previous parser bug', new \DateTimeImmutable());
        $this->em->flush();

        $tester = $this->tester();
        $exit = $tester->execute(['envelope-id' => $envelope->id->toString()]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('Re-dispatched', $tester->getDisplay());
    }

    public function testRejectsInvalidUuid(): void
    {
        $tester = $this->tester();
        $exit = $tester->execute(['envelope-id' => 'not-a-uuid']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('UUID', $tester->getDisplay());
    }

    public function testReportsMissingEnvelope(): void
    {
        $tester = $this->tester();

        $this->expectException(\Throwable::class);

        $tester->execute(['envelope-id' => Uuid::uuid7()->toString()]);
    }

    private function makeEnvelope(): ReceivedReportEmail
    {
        $envelope = new ReceivedReportEmail(
            id: $this->identityProvider->nextIdentity(),
            source: ReportSource::CentralInbox,
            messageId: '<reproc-'.bin2hex(random_bytes(8)).'@x>',
            fromAddress: 'a@example.com',
            subject: 'x',
            receivedAt: new \DateTimeImmutable(),
            ingestedAt: new \DateTimeImmutable(),
            sizeBytes: 1,
            rawEml: 'x',
        );
        $this->em->persist($envelope);

        return $envelope;
    }

    private function tester(): CommandTester
    {
        $kernel = self::$kernel ?? self::bootKernel();
        $application = new Application($kernel);

        return new CommandTester($application->find('sendvery:reports:reprocess'));
    }
}
