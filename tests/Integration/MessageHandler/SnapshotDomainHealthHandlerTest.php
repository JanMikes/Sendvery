<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\DnsCheckResult;
use App\Entity\DomainHealthSnapshot;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Message\SnapshotDomainHealth;
use App\MessageHandler\SnapshotDomainHealthHandler;
use App\Tests\IntegrationTestCase;
use App\Value\DnsCheckType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

final class SnapshotDomainHealthHandlerTest extends IntegrationTestCase
{
    #[Test]
    public function writesOneSnapshotWithExpectedScoreAndGradeFromLatestDnsRows(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $domainId = $this->seedDomainWithDnsResults($em, validity: [
            DnsCheckType::Spf->value => true,
            DnsCheckType::Dkim->value => true,
            DnsCheckType::Dmarc->value => true,
            DnsCheckType::Mx->value => true,
        ]);

        $this->getService(SnapshotDomainHealthHandler::class)(new SnapshotDomainHealth(domainId: $domainId));
        $em->flush();
        $em->clear();

        $snapshots = $em->getRepository(DomainHealthSnapshot::class)->findBy([
            'monitoredDomain' => $domainId->toString(),
        ]);
        self::assertCount(1, $snapshots);

        $snapshot = $snapshots[0];
        self::assertSame(100, $snapshot->score);
        self::assertSame('A', $snapshot->grade);
        self::assertSame(100, $snapshot->spfScore);
        self::assertSame(100, $snapshot->dkimScore);
        self::assertSame(100, $snapshot->dmarcScore);
        self::assertSame(100, $snapshot->mxScore);
        self::assertSame(100, $snapshot->blacklistScore);
        self::assertNotNull($snapshot->shareHash);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $snapshot->shareHash);
        self::assertSame([], $snapshot->recommendations);
    }

    #[Test]
    public function allInvalidDnsResultsProduceGradeFAndStampCheckedAt(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $domainId = $this->seedDomainWithDnsResults($em, validity: [
            DnsCheckType::Spf->value => false,
            DnsCheckType::Dkim->value => false,
            DnsCheckType::Dmarc->value => false,
            DnsCheckType::Mx->value => false,
        ]);

        $before = new \DateTimeImmutable();
        $this->getService(SnapshotDomainHealthHandler::class)(new SnapshotDomainHealth(domainId: $domainId));
        $em->flush();
        $em->clear();
        $after = new \DateTimeImmutable();

        $snapshot = $em->getRepository(DomainHealthSnapshot::class)->findOneBy([
            'monitoredDomain' => $domainId->toString(),
        ]);
        self::assertNotNull($snapshot);
        // All-invalid + blacklist default 100 = 20 -> F.
        self::assertSame(20, $snapshot->score);
        self::assertSame('F', $snapshot->grade);
        self::assertGreaterThanOrEqual($before->getTimestamp(), $snapshot->checkedAt->getTimestamp());
        self::assertLessThanOrEqual($after->getTimestamp(), $snapshot->checkedAt->getTimestamp());
    }

    #[Test]
    public function appendsAnotherSnapshotOnEachInvocation(): void
    {
        // Architect: idempotency guard intentionally NOT added in v1.
        $em = $this->getService(EntityManagerInterface::class);
        $domainId = $this->seedDomainWithDnsResults($em, validity: [
            DnsCheckType::Spf->value => true,
            DnsCheckType::Dkim->value => true,
            DnsCheckType::Dmarc->value => true,
            DnsCheckType::Mx->value => true,
        ]);

        $handler = $this->getService(SnapshotDomainHealthHandler::class);
        $handler(new SnapshotDomainHealth(domainId: $domainId));
        $handler(new SnapshotDomainHealth(domainId: $domainId));
        $em->flush();
        $em->clear();

        $snapshots = $em->getRepository(DomainHealthSnapshot::class)->findBy([
            'monitoredDomain' => $domainId->toString(),
        ]);
        self::assertCount(2, $snapshots);
    }

    /**
     * @param array<string, bool> $validity keyed by DnsCheckType value
     */
    private function seedDomainWithDnsResults(EntityManagerInterface $em, array $validity): UuidInterface
    {
        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Snapshot Test Team',
            slug: 'snapshot-team-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $team->popEvents();
        $em->persist($team);

        $domainId = Uuid::uuid7();
        $domain = new MonitoredDomain(
            id: $domainId,
            team: $team,
            domain: 'snapshot.example',
            createdAt: new \DateTimeImmutable(),
        );
        $domain->popEvents();
        $em->persist($domain);

        foreach ($validity as $typeValue => $isValid) {
            $type = DnsCheckType::from($typeValue);
            $result = new DnsCheckResult(
                id: Uuid::uuid7(),
                monitoredDomain: $domain,
                type: $type,
                checkedAt: new \DateTimeImmutable(),
                rawRecord: $isValid ? 'scripted' : null,
                isValid: $isValid,
                issues: [],
                details: [],
                previousRawRecord: null,
                hasChanged: false,
                isFirstCheck: true,
            );
            $result->popEvents();
            $em->persist($result);
        }

        $em->flush();
        $em->clear();

        return $domainId;
    }
}
