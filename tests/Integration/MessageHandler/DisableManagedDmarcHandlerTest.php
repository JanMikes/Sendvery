<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Message\DisableManagedDmarc;
use App\MessageHandler\DisableManagedDmarcHandler;
use App\Repository\MonitoredDomainRepository;
use App\Services\Dns\FakeDnsRecordPublisher;
use App\Tests\IntegrationTestCase;
use App\Tests\ScriptsDnsRecords;
use App\Value\DmarcPolicy;
use App\Value\Dns\AutoRampStage;
use App\Value\Dns\DmarcSetupMode;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

final class DisableManagedDmarcHandlerTest extends IntegrationTestCase
{
    use ScriptsDnsRecords;

    #[Test]
    public function defersHostedTxtDeletionWhileCnameStillPointsAtUs(): void
    {
        // CNAME still resolves to us — tearing down now would NXDOMAIN their DMARC.
        $this->scriptDns()->withCname('_dmarc.acme.example', 'acme.example._dmarc.sendvery.test');
        $domainId = $this->managedDomainWithHostedRecord('acme.example');

        $this->handle(new DisableManagedDmarc($domainId, $this->teamIdFor($domainId), null));

        $publisher = $this->getService(FakeDnsRecordPublisher::class);
        self::assertTrue($publisher->policyRecordExists('acme.example'), 'Hosted record must be kept while the CNAME still points at us.');

        $domain = $this->getService(MonitoredDomainRepository::class)->get($domainId);
        self::assertSame(DmarcSetupMode::SelfTxt, $domain->dmarcSetupMode);
        self::assertNotNull($domain->cloudflareHostedDmarcRecordId, 'Record id kept for the dangling-safe sync cron.');
        self::assertNotNull($domain->hostedDmarcTeardownAt);
    }

    #[Test]
    public function deletesOnceTheCnameIsGone(): void
    {
        // No CNAME → safe to tear down immediately.
        $domainId = $this->managedDomainWithHostedRecord('acme.example');

        $this->handle(new DisableManagedDmarc($domainId, $this->teamIdFor($domainId), null));

        $publisher = $this->getService(FakeDnsRecordPublisher::class);
        self::assertFalse($publisher->policyRecordExists('acme.example'), 'Hosted record must be deleted once the CNAME is gone.');

        $domain = $this->getService(MonitoredDomainRepository::class)->get($domainId);
        self::assertNull($domain->cloudflareHostedDmarcRecordId);
    }

    #[Test]
    public function rejectsAForgedRequestForAnotherTeamsDomain(): void
    {
        $domainId = $this->managedDomainWithHostedRecord('acme.example');

        $this->expectException(\RuntimeException::class);
        $this->handle(new DisableManagedDmarc($domainId, Uuid::uuid7()->toString(), null));
    }

    private function handle(DisableManagedDmarc $message): void
    {
        $this->getService(DisableManagedDmarcHandler::class)($message);
        $this->getService(EntityManagerInterface::class)->flush();
    }

    private function managedDomainWithHostedRecord(string $domain): UuidInterface
    {
        $em = $this->getService(EntityManagerInterface::class);
        $publisher = $this->getService(FakeDnsRecordPublisher::class);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Disable Managed',
            slug: 'disable-managed-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
            plan: 'pro',
        );
        $team->popEvents();
        $em->persist($team);

        $domainId = Uuid::uuid7();
        $entity = new MonitoredDomain(id: $domainId, team: $team, domain: $domain, createdAt: new \DateTimeImmutable('-30 days'));
        $entity->dmarcSetupMode = DmarcSetupMode::ManagedCname;
        $entity->managedPolicyP = DmarcPolicy::Quarantine;
        $entity->autoRampStage = AutoRampStage::Quarantine;
        $entity->managedDmarcEnabledAt = new \DateTimeImmutable('-20 days');
        $entity->cnameVerifiedAt = new \DateTimeImmutable('-19 days');
        $entity->cloudflareHostedDmarcRecordId = $publisher->publishPolicyRecord($domain, 'v=DMARC1; p=quarantine; rua=mailto:reports@sendvery.test');
        $entity->popEvents();
        $em->persist($entity);
        $em->flush();

        return $domainId;
    }

    private function teamIdFor(UuidInterface $domainId): string
    {
        return $this->getService(MonitoredDomainRepository::class)->get($domainId)->team->id->toString();
    }
}
