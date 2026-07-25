<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Message\CheckDomainDns;
use App\Message\EnableManagedDmarc;
use App\MessageHandler\EnableManagedDmarcHandler;
use App\Tests\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class ScheduleDnsRechecksWhenManagedDmarcEnabledTest extends IntegrationTestCase
{
    #[Test]
    public function enablingManagedDmarcSchedulesDelayedCnameRechecks(): void
    {
        $em = $this->getService(EntityManagerInterface::class);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Recheck Ladder',
            slug: 'recheck-ladder-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
            plan: 'pro',
        );
        $team->popEvents();
        $em->persist($team);

        $domainId = Uuid::uuid7();
        $domain = new MonitoredDomain(
            id: $domainId,
            team: $team,
            domain: 'recheck-ladder.example',
            createdAt: new \DateTimeImmutable(),
        );
        $domain->popEvents();
        $em->persist($domain);
        $em->flush();

        $this->getService(EnableManagedDmarcHandler::class)(new EnableManagedDmarc(
            $domainId,
            $team->id->toString(),
            null,
        ));
        $em->flush();

        $delays = [];
        foreach ($this->asyncTransport()->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if (!$message instanceof CheckDomainDns || !$domainId->equals($message->domainId)) {
                continue;
            }

            $stamp = $envelope->last(DelayStamp::class);
            $delays[] = null !== $stamp ? $stamp->getDelay() : 0;
        }

        sort($delays);
        self::assertSame(
            [300_000, 1_800_000, 7_200_000],
            $delays,
            'Enabling managed DMARC must schedule re-checks at 5 min, 30 min and 2 h so a freshly-added CNAME is verified without waiting for the nightly sweep.',
        );
    }

    private function asyncTransport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        assert($transport instanceof InMemoryTransport);

        return $transport;
    }
}
