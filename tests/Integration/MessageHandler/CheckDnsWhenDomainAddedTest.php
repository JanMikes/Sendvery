<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\Team;
use App\Message\AddDomain;
use App\Message\CheckDomainDns;
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

    private function asyncTransport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        assert($transport instanceof InMemoryTransport);

        return $transport;
    }
}
