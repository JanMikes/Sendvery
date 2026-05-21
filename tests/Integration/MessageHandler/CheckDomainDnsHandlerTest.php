<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\DnsCheckResult;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Message\CheckDomainDns;
use App\MessageHandler\CheckDomainDnsHandler;
use App\Tests\IntegrationTestCase;
use App\Tests\ScriptsDnsRecords;
use App\Value\DnsCheckType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

final class CheckDomainDnsHandlerTest extends IntegrationTestCase
{
    use ScriptsDnsRecords;

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

        $resultByType = [];
        foreach ($results as $r) {
            $resultByType[$r->type->value] = $r;
        }

        $updatedDomain = $em->find(MonitoredDomain::class, $domainId);
        self::assertNotNull($updatedDomain);

        // The handler should set *VerifiedAt iff the corresponding result is valid,
        // independent of what live DNS returned for this domain at test time.
        self::assertSame(
            $resultByType['spf']->isValid,
            null !== $updatedDomain->spfVerifiedAt,
        );
        self::assertSame(
            $resultByType['dkim']->isValid,
            null !== $updatedDomain->dkimVerifiedAt,
        );
        self::assertSame(
            $resultByType['dmarc']->isValid,
            null !== $updatedDomain->dmarcVerifiedAt,
        );
    }

    #[Test]
    public function writesValidDmarcResultWhenDnsHasIt(): void
    {
        $this->scriptDns()->withTxt(
            '_dmarc.scripted.example',
            'v=DMARC1; p=quarantine; rua=mailto:reports@sendvery.com;',
        );

        $domainId = $this->createDomain('scripted.example');

        $handler = $this->getService(CheckDomainDnsHandler::class);
        $handler(new CheckDomainDns(domainId: $domainId));

        $em = $this->getService(EntityManagerInterface::class);
        $em->flush();
        $em->clear();

        $dmarc = $this->latestCheck($em, $domainId, DnsCheckType::Dmarc);
        self::assertNotNull($dmarc);
        self::assertTrue($dmarc->isValid, 'Handler must mark DMARC valid when a parseable p= record is scripted');
        self::assertStringContainsString('v=DMARC1', (string) $dmarc->rawRecord);
        self::assertSame('quarantine', $dmarc->details['policy'] ?? null);
        self::assertContains('reports@sendvery.com', $dmarc->details['rua_addresses'] ?? []);

        $domain = $em->find(MonitoredDomain::class, $domainId);
        self::assertNotNull($domain);
        self::assertNotNull($domain->dmarcVerifiedAt, 'A valid DMARC check must set dmarc_verified_at on the domain');
    }

    #[Test]
    public function spfLookupLimitTrippedAtElevenIncludes(): void
    {
        // RFC 7208 caps SPF DNS lookups at 10. Eleven include: mechanisms must
        // produce isValid=false with a Critical lookup-limit issue surfaced.
        $includes = array_map(static fn (int $i): string => "include:spf{$i}.example", range(1, 11));
        $spfRecord = 'v=spf1 '.implode(' ', $includes).' ~all';

        $this->scriptDns()->withTxt('spfheavy.example', $spfRecord);

        $domainId = $this->createDomain('spfheavy.example');

        $handler = $this->getService(CheckDomainDnsHandler::class);
        $handler(new CheckDomainDns(domainId: $domainId));

        $em = $this->getService(EntityManagerInterface::class);
        $em->flush();
        $em->clear();

        $spf = $this->latestCheck($em, $domainId, DnsCheckType::Spf);
        self::assertNotNull($spf);
        self::assertFalse($spf->isValid, 'SPF must be invalid when lookup count exceeds 10');
        self::assertSame(11, $spf->details['lookup_count'] ?? null);

        $messages = array_map(static fn (array $issue): string => $issue['message'], $spf->issues);
        self::assertNotEmpty(array_filter($messages, static fn (string $m): bool => str_contains($m, 'exceeding the 10-lookup limit')));
    }

    #[Test]
    public function mxRecordIsCapturedAndProbedThroughSmtpFake(): void
    {
        // End-to-end MX flow without any network: script the MX record, the A
        // record for the MX target, AND a reachable STARTTLS-capable SMTP probe
        // at the resolved IP. Handler should capture host, priority, IP,
        // reachable=true, tls_supported=true on the resulting DnsCheckResult.
        $this->scriptDns()
            ->withMx('mxhost.example', 'mail.mxhost.example', priority: 10)
            ->withA('mail.mxhost.example', '192.0.2.10');

        $this->scriptSmtp()->withReachable('192.0.2.10', tlsSupported: true);

        $domainId = $this->createDomain('mxhost.example');

        $handler = $this->getService(CheckDomainDnsHandler::class);
        $handler(new CheckDomainDns(domainId: $domainId));

        $em = $this->getService(EntityManagerInterface::class);
        $em->flush();
        $em->clear();

        $mx = $this->latestCheck($em, $domainId, DnsCheckType::Mx);
        self::assertNotNull($mx);

        /** @var list<array{host: string, priority: int, ip: ?string, reachable: bool, tls_supported: ?bool}> $records */
        $records = $mx->details['records'] ?? [];
        self::assertCount(1, $records, 'MX records must be captured in the check result details');
        self::assertSame('mail.mxhost.example', $records[0]['host']);
        self::assertSame(10, $records[0]['priority']);
        self::assertSame('192.0.2.10', $records[0]['ip']);
        self::assertTrue($records[0]['reachable']);
        self::assertTrue($records[0]['tls_supported']);
    }

    #[Test]
    public function mxRecordWithUnreachableServerSurfacesWarning(): void
    {
        // Same MX + A scripting, but the SMTP probe returns unreachable. The
        // check must capture reachable=false and the issues list must include
        // the "responded on port 25" warning.
        $this->scriptDns()
            ->withMx('mxhost.example', 'mail.mxhost.example', priority: 10)
            ->withA('mail.mxhost.example', '192.0.2.10');

        $this->scriptSmtp()->withUnreachable('192.0.2.10');

        $domainId = $this->createDomain('mxhost.example');

        $handler = $this->getService(CheckDomainDnsHandler::class);
        $handler(new CheckDomainDns(domainId: $domainId));

        $em = $this->getService(EntityManagerInterface::class);
        $em->flush();
        $em->clear();

        $mx = $this->latestCheck($em, $domainId, DnsCheckType::Mx);
        self::assertNotNull($mx);

        /** @var list<array{reachable: bool}> $records */
        $records = $mx->details['records'] ?? [];
        self::assertCount(1, $records);
        self::assertFalse($records[0]['reachable']);

        $messages = array_map(static fn (array $issue): string => $issue['message'], $mx->issues);
        self::assertNotEmpty(array_filter($messages, static fn (string $m): bool => str_contains($m, 'responded on port 25')));
    }

    #[Test]
    public function detectsDmarcRemovedOnNextCheck(): void
    {
        $this->scriptDns()->withTxt(
            '_dmarc.removed.example',
            'v=DMARC1; p=none; rua=mailto:reports@sendvery.com;',
        );

        $domainId = $this->createDomain('removed.example');

        $handler = $this->getService(CheckDomainDnsHandler::class);
        $em = $this->getService(EntityManagerInterface::class);

        // First check: DNS is configured, result is valid.
        $handler(new CheckDomainDns(domainId: $domainId));
        $em->flush();

        // User wipes DMARC from their DNS panel — drop the scripted record.
        $this->scriptDns()->reset();

        // Second check: DNS is gone now. Result should be invalid, hasChanged=true,
        // and previousRawRecord must point back at the original record.
        $handler(new CheckDomainDns(domainId: $domainId));
        $em->flush();
        $em->clear();

        $repo = $em->getRepository(DnsCheckResult::class);
        $rows = $repo->createQueryBuilder('d')
            ->where('d.monitoredDomain = :domainId')
            ->andWhere('d.type = :type')
            ->setParameter('domainId', $domainId->toString())
            ->setParameter('type', DnsCheckType::Dmarc->value)
            ->orderBy('d.checkedAt', 'ASC')
            ->getQuery()
            ->getResult();

        self::assertIsArray($rows);
        self::assertCount(2, $rows);

        [$first, $second] = $rows;
        self::assertInstanceOf(DnsCheckResult::class, $first);
        self::assertInstanceOf(DnsCheckResult::class, $second);

        self::assertTrue($first->isValid);
        self::assertFalse($second->isValid);
        self::assertTrue($second->hasChanged);
        self::assertSame($first->rawRecord, $second->previousRawRecord);
    }

    private function createDomain(string $domain): UuidInterface
    {
        $em = $this->getService(EntityManagerInterface::class);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Scripted DNS Team',
            slug: 'scripted-dns-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $team->popEvents();
        $em->persist($team);

        $domainId = Uuid::uuid7();
        $entity = new MonitoredDomain(
            id: $domainId,
            team: $team,
            domain: $domain,
            createdAt: new \DateTimeImmutable(),
        );
        $entity->popEvents();
        $em->persist($entity);
        $em->flush();
        $em->clear();

        return $domainId;
    }

    private function latestCheck(EntityManagerInterface $em, UuidInterface $domainId, DnsCheckType $type): ?DnsCheckResult
    {
        $result = $em->getRepository(DnsCheckResult::class)
            ->createQueryBuilder('d')
            ->where('d.monitoredDomain = :domainId')
            ->andWhere('d.type = :type')
            ->setParameter('domainId', $domainId->toString())
            ->setParameter('type', $type->value)
            ->orderBy('d.checkedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        assert(null === $result || $result instanceof DnsCheckResult);

        return $result;
    }
}
