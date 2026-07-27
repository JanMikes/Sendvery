<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\Team;
use App\Message\AddDomain;
use App\Message\CheckDomainDns;
use App\Message\SnapshotDomainHealth;
use App\MessageHandler\AddDomainHandler;
use App\Tests\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class CheckDnsWhenDomainAddedTest extends IntegrationTestCase
{
    #[Test]
    public function addingADomainQueuesItsFirstDnsCheck(): void
    {
        $em = $this->getService(EntityManagerInterface::class);

        $teamId = Uuid::uuid7();
        $team = new Team(
            id: $teamId,
            name: 'First Check Team',
            slug: 'first-check-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $team->popEvents();
        $em->persist($team);
        $em->flush();

        $domainId = Uuid::uuid7();
        $this->getService(AddDomainHandler::class)(new AddDomain(
            domainId: $domainId,
            teamId: $teamId,
            domainName: 'first-check.example',
        ));
        $em->flush();

        $queuedChecks = array_filter(
            $this->asyncTransport()->getSent(),
            static fn ($envelope): bool => $envelope->getMessage() instanceof CheckDomainDns,
        );

        self::assertCount(1, $queuedChecks, 'Adding a domain must queue its first DNS check so the user is not left waiting for the nightly sweep.');
        $message = array_values($queuedChecks)[0]->getMessage();
        assert($message instanceof CheckDomainDns);
        self::assertTrue($domainId->equals($message->domainId));
    }

    #[Test]
    public function addingADomainAlsoQueuesItsFirstHealthSnapshotAfterTheCheck(): void
    {
        // Without this, the grade / score / category surfaces stay empty until
        // the 03:00 cron even though the domain was fully checked minutes after
        // it was added — the page says "no health score yet" about a domain we
        // have already measured.
        $em = $this->getService(EntityManagerInterface::class);

        $teamId = Uuid::uuid7();
        $team = new Team(
            id: $teamId,
            name: 'Snapshot Team',
            slug: 'snapshot-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $team->popEvents();
        $em->persist($team);
        $em->flush();

        $domainId = Uuid::uuid7();
        $this->getService(AddDomainHandler::class)(new AddDomain(
            domainId: $domainId,
            teamId: $teamId,
            domainName: 'snapshot.example',
        ));
        $em->flush();

        $sent = $this->asyncTransport()->getSent();
        $messages = array_values(array_map(static fn ($envelope): object => $envelope->getMessage(), $sent));

        $snapshots = array_values(array_filter(
            $messages,
            static fn ($message): bool => $message instanceof SnapshotDomainHealth,
        ));
        self::assertCount(1, $snapshots, 'A first health snapshot must be queued alongside the first check.');
        self::assertTrue($domainId->equals($snapshots[0]->domainId));

        // Ordering matters: the snapshot summarises the check results, so it has
        // to be enqueued behind the check on the same FIFO transport.
        $checkPosition = $this->positionOf($messages, CheckDomainDns::class);
        $snapshotPosition = $this->positionOf($messages, SnapshotDomainHealth::class);
        self::assertLessThan(
            $snapshotPosition,
            $checkPosition,
            'The snapshot must be queued after the check whose results it reads.',
        );
    }

    /**
     * @param list<object> $messages
     */
    private function positionOf(array $messages, string $class): int
    {
        foreach ($messages as $index => $message) {
            if ($message instanceof $class) {
                return $index;
            }
        }

        self::fail(sprintf('No %s was dispatched.', $class));
    }

    private function asyncTransport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        assert($transport instanceof InMemoryTransport);

        return $transport;
    }
}
