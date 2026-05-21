<?php

declare(strict_types=1);

namespace App\Tests\Integration\Query;

use App\Entity\DnsCheckResult;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Query\GetDomainVerificationStatus;
use App\Tests\IntegrationTestCase;
use App\Value\DnsCheckType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;

final class GetDomainVerificationStatusTest extends IntegrationTestCase
{
    #[Test]
    public function returnsNullWhenTeamHasNoDomain(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetDomainVerificationStatus::class);

        $teamId = Uuid::uuid7();
        $team = new Team(
            id: $teamId,
            name: 'Empty',
            slug: 'empty-'.$teamId->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);
        $em->flush();

        self::assertNull($query->forTeam($teamId));
    }

    #[Test]
    public function returnsTimestampsAndCurrentValidity(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetDomainVerificationStatus::class);

        $teamId = Uuid::uuid7();
        $team = new Team(
            id: $teamId,
            name: 'Verify',
            slug: 'verify-'.$teamId->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);

        $domainId = Uuid::uuid7();
        $verifiedAt = new \DateTimeImmutable('2026-05-01 09:00:00');
        $domain = new MonitoredDomain(
            id: $domainId,
            team: $team,
            domain: 'verify.example',
            createdAt: new \DateTimeImmutable(),
            spfVerifiedAt: $verifiedAt,
            dmarcVerifiedAt: $verifiedAt,
        );
        $em->persist($domain);

        // Older check valid, newest check invalid → currently NOT valid.
        $oldCheck = new DnsCheckResult(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            type: DnsCheckType::Dmarc,
            checkedAt: new \DateTimeImmutable('2026-05-10 09:00:00'),
            rawRecord: 'v=DMARC1; p=none;',
            isValid: true,
            issues: [],
            details: [],
            previousRawRecord: null,
            hasChanged: false,
            isFirstCheck: true,
        );
        $em->persist($oldCheck);

        $newCheck = new DnsCheckResult(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            type: DnsCheckType::Dmarc,
            checkedAt: new \DateTimeImmutable('2026-05-15 09:00:00'),
            rawRecord: null,
            isValid: false,
            issues: [],
            details: [],
            previousRawRecord: 'v=DMARC1; p=none;',
            hasChanged: true,
            isFirstCheck: false,
        );
        $em->persist($newCheck);
        $em->flush();

        $status = $query->forTeam($teamId);

        self::assertNotNull($status);
        self::assertSame($domainId->toString(), $status->domainId);
        self::assertSame('verify.example', $status->domainName);
        self::assertNotNull($status->dmarcVerifiedAt);
        self::assertNotNull($status->spfVerifiedAt);
        self::assertNull($status->firstReportAt);
        self::assertFalse($status->dmarcCurrentlyValid);
    }
}
