<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\DnsCheckResult;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Message\CheckDomainDns;
use App\MessageHandler\CheckDomainDnsHandler;
use App\Tests\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;

final class CheckDomainDnsHandlerTest extends IntegrationTestCase
{
    #[Test]
    public function createsDnsCheckResultsForDomain(): void
    {
        $em = $this->getService(EntityManagerInterface::class);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'DNS Check Team',
            slug: 'dns-check-team-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $team->popEvents();
        $em->persist($team);

        $domainId = Uuid::uuid7();
        $domain = new MonitoredDomain(
            id: $domainId,
            team: $team,
            domain: 'example.com',
            createdAt: new \DateTimeImmutable(),
        );
        $domain->popEvents();
        $em->persist($domain);
        $em->flush();
        $em->clear();

        $handler = $this->getService(CheckDomainDnsHandler::class);
        $handler(new CheckDomainDns(domainId: $domainId));
        $em->flush();

        $results = $em->getRepository(DnsCheckResult::class)->findBy([
            'monitoredDomain' => $domainId->toString(),
        ]);

        self::assertCount(4, $results);

        $types = array_map(fn (DnsCheckResult $r) => $r->type->value, $results);
        sort($types);
        self::assertSame(['dkim', 'dmarc', 'mx', 'spf'], $types);
    }
}
