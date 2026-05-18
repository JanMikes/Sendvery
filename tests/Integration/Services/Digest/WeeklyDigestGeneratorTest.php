<?php

declare(strict_types=1);

namespace App\Tests\Integration\Services\Digest;

use App\Entity\DnsCheckResult;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Services\Digest\WeeklyDigestGenerator;
use App\Tests\IntegrationTestCase;
use App\Value\DnsCheckType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;

final class WeeklyDigestGeneratorTest extends IntegrationTestCase
{
    #[Test]
    public function surfacesCurrentlyBrokenDnsInDigest(): void
    {
        [$team, $domain, $em] = $this->createTeamAndDomain();
        $generator = $this->getService(WeeklyDigestGenerator::class);

        // Older check that was healthy
        $em->persist(new DnsCheckResult(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            type: DnsCheckType::Dkim,
            checkedAt: new \DateTimeImmutable('-2 days'),
            rawRecord: 'v=DKIM1; k=rsa; p=ABC',
            isValid: true,
            issues: [],
            details: [],
            previousRawRecord: null,
            hasChanged: false,
        ));

        // Most recent check is invalid — should surface
        $em->persist(new DnsCheckResult(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            type: DnsCheckType::Dkim,
            checkedAt: new \DateTimeImmutable('-1 hour'),
            rawRecord: null,
            isValid: false,
            issues: [['severity' => 'warning', 'message' => 'CNAME points to nonexistent target', 'recommendation' => 'Fix CNAME']],
            details: [],
            previousRawRecord: 'v=DKIM1; k=rsa; p=ABC',
            hasChanged: true,
        ));

        $em->flush();

        $digest = $generator->generate($team);

        self::assertCount(1, $digest->currentlyBrokenDns);
        self::assertSame('dns-digest-test.com', $digest->currentlyBrokenDns[0]->domainName);
        self::assertSame('DKIM', $digest->currentlyBrokenDns[0]->checkType);
        self::assertContains('CNAME points to nonexistent target', $digest->currentlyBrokenDns[0]->issueMessages);
    }

    #[Test]
    public function excludesRecordsThatRecoveredOnLatestCheck(): void
    {
        [$team, $domain, $em] = $this->createTeamAndDomain();
        $generator = $this->getService(WeeklyDigestGenerator::class);

        $em->persist(new DnsCheckResult(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            type: DnsCheckType::Spf,
            checkedAt: new \DateTimeImmutable('-1 day'),
            rawRecord: null,
            isValid: false,
            issues: [],
            details: [],
            previousRawRecord: null,
            hasChanged: false,
        ));

        // Latest check is healthy — must NOT appear in currentlyBrokenDns
        $em->persist(new DnsCheckResult(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            type: DnsCheckType::Spf,
            checkedAt: new \DateTimeImmutable('-1 hour'),
            rawRecord: 'v=spf1 ~all',
            isValid: true,
            issues: [],
            details: [],
            previousRawRecord: null,
            hasChanged: true,
        ));

        $em->flush();

        $digest = $generator->generate($team);

        self::assertCount(0, $digest->currentlyBrokenDns);
    }

    /** @return array{Team, MonitoredDomain, EntityManagerInterface} */
    private function createTeamAndDomain(): array
    {
        $em = $this->getService(EntityManagerInterface::class);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Digest Generator Test',
            slug: 'digest-gen-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $team->popEvents();
        $em->persist($team);

        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'dns-digest-test.com',
            createdAt: new \DateTimeImmutable(),
        );
        $domain->popEvents();
        $em->persist($domain);
        $em->flush();

        return [$team, $domain, $em];
    }
}
