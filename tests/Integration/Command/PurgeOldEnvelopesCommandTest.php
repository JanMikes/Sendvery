<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\ReceivedReportEmail;
use App\Services\IdentityProvider;
use App\Tests\IntegrationTestCase;
use App\Value\Reports\EnvelopeProcessingStatus;
use App\Value\Reports\ReportSource;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class PurgeOldEnvelopesCommandTest extends IntegrationTestCase
{
    private EntityManagerInterface $em;
    private IdentityProvider $identityProvider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em = $this->getService(EntityManagerInterface::class);
        $this->identityProvider = $this->getService(IdentityProvider::class);
    }

    public function testPurgesParsedEnvelopesOlderThanRetentionWindow(): void
    {
        $oldParsed = $this->makeEnvelope(EnvelopeProcessingStatus::Parsed, new \DateTimeImmutable('-100 days'));
        $recentParsed = $this->makeEnvelope(EnvelopeProcessingStatus::Parsed, new \DateTimeImmutable('-1 day'));
        $oldFailed = $this->makeEnvelope(EnvelopeProcessingStatus::Failed, new \DateTimeImmutable('-100 days'));
        $oldPending = $this->makeEnvelope(EnvelopeProcessingStatus::Pending, new \DateTimeImmutable('-100 days'));
        $this->em->flush();

        $exit = $this->tester()->execute([]);

        self::assertSame(0, $exit);
        $this->em->clear();

        self::assertNull($this->em->find(ReceivedReportEmail::class, $oldParsed->id), 'old parsed is gone');
        self::assertNotNull($this->em->find(ReceivedReportEmail::class, $recentParsed->id), 'recent parsed survives');
        self::assertNotNull($this->em->find(ReceivedReportEmail::class, $oldFailed->id), 'failed envelopes survive purge');
        self::assertNotNull($this->em->find(ReceivedReportEmail::class, $oldPending->id), 'pending envelopes survive purge');
    }

    public function testReportsZeroWhenNothingToDelete(): void
    {
        $tester = $this->tester();
        $exit = $tester->execute([]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('No envelopes to purge.', $tester->getDisplay());
    }

    private function makeEnvelope(EnvelopeProcessingStatus $status, \DateTimeImmutable $processedAt): ReceivedReportEmail
    {
        $envelope = new ReceivedReportEmail(
            id: $this->identityProvider->nextIdentity(),
            source: ReportSource::CentralInbox,
            messageId: '<purge-'.bin2hex(random_bytes(8)).'@x>',
            fromAddress: 'a@example.com',
            subject: 'x',
            receivedAt: $processedAt,
            ingestedAt: $processedAt,
            sizeBytes: 1,
            rawEml: 'x',
        );

        match ($status) {
            EnvelopeProcessingStatus::Parsed => $envelope->markParsed($processedAt),
            EnvelopeProcessingStatus::Quarantined => $envelope->markQuarantined($processedAt),
            EnvelopeProcessingStatus::Ignored => $envelope->markIgnored('test', $processedAt),
            EnvelopeProcessingStatus::Failed => $envelope->markFailed('test', $processedAt),
            EnvelopeProcessingStatus::Pending => null,
        };

        $this->em->persist($envelope);

        return $envelope;
    }

    private function tester(): CommandTester
    {
        $kernel = self::$kernel ?? self::bootKernel();
        $application = new Application($kernel);

        return new CommandTester($application->find('sendvery:reports:purge'));
    }
}
