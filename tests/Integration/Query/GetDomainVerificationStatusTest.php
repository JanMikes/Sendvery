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
    public function returnsTimestampsAndConsecutiveFailureCount(): void
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

        // Older check valid, then two failing checks newer → 2 consecutive failures.
        $em->persist($this->dnsCheck($domain, '2026-05-10 09:00:00', true));
        $em->persist($this->dnsCheck($domain, '2026-05-14 09:00:00', false));
        $em->persist($this->dnsCheck($domain, '2026-05-15 09:00:00', false));
        $em->flush();

        $status = $query->forTeam($teamId);

        self::assertNotNull($status);
        self::assertSame($domainId->toString(), $status->domainId);
        self::assertSame('verify.example', $status->domainName);
        self::assertNotNull($status->dmarcVerifiedAt);
        self::assertNotNull($status->spfVerifiedAt);
        self::assertNull($status->firstReportAt);
        self::assertSame(2, $status->consecutiveDmarcFailures);
    }

    #[Test]
    public function consecutiveFailuresResetOnceLatestCheckPasses(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $query = $this->getService(GetDomainVerificationStatus::class);

        $teamId = Uuid::uuid7();
        $team = new Team(
            id: $teamId,
            name: 'Recover',
            slug: 'recover-'.$teamId->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);

        $verifiedAt = new \DateTimeImmutable('2026-05-01 09:00:00');
        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'recover.example',
            createdAt: new \DateTimeImmutable(),
            dmarcVerifiedAt: $verifiedAt,
        );
        $em->persist($domain);

        // failed, failed, then valid → count must reset to 0.
        $em->persist($this->dnsCheck($domain, '2026-05-10 09:00:00', false));
        $em->persist($this->dnsCheck($domain, '2026-05-11 09:00:00', false));
        $em->persist($this->dnsCheck($domain, '2026-05-12 09:00:00', true));
        $em->flush();

        $status = $query->forTeam($teamId);

        self::assertNotNull($status);
        self::assertSame(0, $status->consecutiveDmarcFailures);
    }

    private function dnsCheck(MonitoredDomain $domain, string $when, bool $isValid): DnsCheckResult
    {
        return new DnsCheckResult(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            type: DnsCheckType::Dmarc,
            checkedAt: new \DateTimeImmutable($when),
            rawRecord: $isValid ? 'v=DMARC1; p=none;' : null,
            isValid: $isValid,
            issues: [],
            details: [],
            previousRawRecord: null,
            hasChanged: false,
            isFirstCheck: false,
        );
    }
}
