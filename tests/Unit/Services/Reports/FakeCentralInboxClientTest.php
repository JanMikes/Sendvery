<?php

declare(strict_types=1);

namespace App\Tests\Unit\Services\Reports;

use App\Services\Reports\FakeCentralInboxClient;
use App\Value\Reports\CentralInboxFolder;
use App\Value\Reports\FetchedEnvelope;
use PHPUnit\Framework\TestCase;

final class FakeCentralInboxClientTest extends TestCase
{
    public function testReturnsAddedEnvelopesAndDrainsAfterFetch(): void
    {
        $client = new FakeCentralInboxClient();
        $envelope = $this->makeEnvelope(uid: 1);

        $client->addEnvelope($envelope);

        self::assertSame([$envelope], $client->fetchPending());
        self::assertSame([], $client->fetchPending(), 'second fetch returns empty — envelopes drain on fetch');
    }

    public function testTracksSeenUids(): void
    {
        $client = new FakeCentralInboxClient();

        $client->markSeen(7);
        $client->markSeen(8);

        self::assertSame([7, 8], $client->getSeenUids());
    }

    public function testTracksProcessedMoves(): void
    {
        $client = new FakeCentralInboxClient();

        $client->moveProcessed(7, 555, '<a@x>', CentralInboxFolder::Processed);
        $client->moveProcessed(null, null, '<b@x>', CentralInboxFolder::Junk);

        self::assertSame(
            [
                ['uid' => 7, 'uidvalidity' => 555, 'messageId' => '<a@x>', 'destination' => CentralInboxFolder::Processed],
                ['uid' => null, 'uidvalidity' => null, 'messageId' => '<b@x>', 'destination' => CentralInboxFolder::Junk],
            ],
            $client->getMovedProcessed(),
        );
    }

    public function testCloseCounts(): void
    {
        $client = new FakeCentralInboxClient();

        $client->close();
        $client->close();

        self::assertSame(2, $client->getClosedTimes());
    }

    public function testSimulateFailureBubblesUpOnFetch(): void
    {
        $client = new FakeCentralInboxClient();
        $client->simulateFailure('IMAP down');

        $this->expectExceptionMessage('IMAP down');
        $client->fetchPending();
    }

    public function testSimulateFailureSurfacesOnTestConnection(): void
    {
        $client = new FakeCentralInboxClient();
        $client->simulateFailure('IMAP down');

        $result = $client->testConnection();

        self::assertFalse($result->success);
        self::assertSame('IMAP down', $result->error);
    }

    public function testTestConnectionReportsPendingCount(): void
    {
        $client = new FakeCentralInboxClient();
        $client->addEnvelope($this->makeEnvelope(uid: 1));
        $client->addEnvelope($this->makeEnvelope(uid: 2));

        $result = $client->testConnection();

        self::assertTrue($result->success);
        self::assertSame(2, $result->mailboxCount);
    }

    public function testResetClearsAllState(): void
    {
        $client = new FakeCentralInboxClient();
        $client->addEnvelope($this->makeEnvelope(uid: 1));
        $client->markSeen(1);
        $client->moveProcessed(1, 1, '<msg-1@example.com>', CentralInboxFolder::Junk);
        $client->close();
        $client->simulateFailure('x');

        $client->reset();

        self::assertSame([], $client->fetchPending());
        self::assertSame([], $client->getSeenUids());
        self::assertSame([], $client->getMovedProcessed());
        self::assertSame(0, $client->getClosedTimes());
        self::assertTrue($client->testConnection()->success);
    }

    private function makeEnvelope(int $uid): FetchedEnvelope
    {
        return new FetchedEnvelope(
            messageId: sprintf('<msg-%d@example.com>', $uid),
            fromAddress: 'dmarc@example.com',
            subject: 'Report',
            receivedAt: new \DateTimeImmutable(),
            rawEml: 'raw',
            uid: $uid,
            uidvalidity: 1,
        );
    }
}
