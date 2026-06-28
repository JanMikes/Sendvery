<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\SyncHostedDmarcRecordsCommand;
use App\Entity\Alert;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Repository\MonitoredDomainRepository;
use App\Services\Dns\CloudflareDnsClient;
use App\Services\Dns\FakeDnsRecordPublisher;
use App\Services\Dns\ManagedDmarcCnameChecker;
use App\Services\Dns\ManagedDmarcPolicyComposer;
use App\Services\ReportAddressProvider;
use App\Tests\IntegrationTestCase;
use App\Tests\ScriptsDnsRecords;
use App\Value\AlertType;
use App\Value\DmarcPolicy;
use App\Value\Dns\AutoRampStage;
use App\Value\Dns\DmarcSetupMode;
use App\Value\Dns\ManagedDmarcPolicy;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Messenger\MessageBusInterface;

final class SyncHostedDmarcRecordsCommandTest extends IntegrationTestCase
{
    use ScriptsDnsRecords;

    #[Test]
    public function recreatesAMissingHostedTxt(): void
    {
        $domainId = $this->managedDomain('acme.example', DmarcPolicy::Quarantine);

        $this->runSync();

        self::assertTrue($this->publisher()->policyRecordExists('acme.example'));
        self::assertNotNull($this->reload($domainId)->cloudflareHostedDmarcRecordId);
    }

    #[Test]
    public function republishesAHostedTxtWhoseContentHasDrifted(): void
    {
        $domainId = $this->managedDomain('acme.example', DmarcPolicy::Reject);
        $this->publisher()->publishPolicyRecord('acme.example', 'v=DMARC1; p=none');

        $this->runSync();

        $expected = $this->composer()->compose(new ManagedDmarcPolicy(DmarcPolicy::Reject));
        self::assertSame($expected, $this->publisher()->getPublishedPolicyContent('acme.example'));
    }

    #[Test]
    public function reconcilesAStaleStoredRecordIdWhenContentIsAlreadyInSync(): void
    {
        $domainId = $this->managedDomain('acme.example', DmarcPolicy::Quarantine);
        // Seed the in-sync content but a stale stored id.
        $this->publisher()->publishPolicyRecord('acme.example', $this->composer()->compose(new ManagedDmarcPolicy(DmarcPolicy::Quarantine)));
        $domain = $this->getService(MonitoredDomainRepository::class)->get($domainId);
        $domain->cloudflareHostedDmarcRecordId = 'stale-id';
        $this->getService(EntityManagerInterface::class)->flush();

        $this->runSync();

        self::assertSame('fake-cf-policy-'.md5('acme.example'), $this->reload($domainId)->cloudflareHostedDmarcRecordId);
    }

    #[Test]
    public function leavesAFullyInSyncRecordUntouched(): void
    {
        $domainId = $this->managedDomain('acme.example', DmarcPolicy::Quarantine);
        $expectedId = $this->publisher()->publishPolicyRecord('acme.example', $this->composer()->compose(new ManagedDmarcPolicy(DmarcPolicy::Quarantine)));
        $domain = $this->getService(MonitoredDomainRepository::class)->get($domainId);
        $domain->cloudflareHostedDmarcRecordId = $expectedId;
        $this->getService(EntityManagerInterface::class)->flush();

        $this->runSync();

        self::assertSame($expectedId, $this->reload($domainId)->cloudflareHostedDmarcRecordId);
    }

    #[Test]
    public function skipsAManagedDomainWithNoPolicyIntent(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $team = new Team(id: Uuid::uuid7(), name: 'Sync', slug: 'sync-'.Uuid::uuid7()->toString(), createdAt: new \DateTimeImmutable(), plan: 'pro');
        $em->persist($team);
        $domainId = Uuid::uuid7();
        $domain = new MonitoredDomain(id: $domainId, team: $team, domain: 'nopolicy.example', createdAt: new \DateTimeImmutable());
        $domain->dmarcSetupMode = DmarcSetupMode::ManagedCname;
        $domain->managedPolicyP = null;
        $em->persist($domain);
        $em->flush();

        $this->runSync();

        self::assertFalse($this->publisher()->policyRecordExists('nopolicy.example'));
    }

    #[Test]
    public function deletesAStaleHostedTxtOnceItsCnameIsGone(): void
    {
        // Offboarded (self-TXT) with a lingering record and NO CNAME → safe to delete.
        $domainId = $this->offboardedDomain('acme.example', cnamePointsAtUs: false);

        $this->runSync();

        self::assertFalse($this->publisher()->policyRecordExists('acme.example'));
        self::assertNull($this->reload($domainId)->cloudflareHostedDmarcRecordId);
    }

    #[Test]
    public function neverDeletesAHostedTxtWhileTheCnameStillPointsAtUs(): void
    {
        $domainId = $this->offboardedDomain('acme.example', cnamePointsAtUs: true);

        $this->runSync();

        self::assertTrue($this->publisher()->policyRecordExists('acme.example'), 'Must not break live DMARC while the CNAME still points at us.');
        self::assertNotNull($this->reload($domainId)->cloudflareHostedDmarcRecordId);

        $alerts = $this->getService(EntityManagerInterface::class)->getRepository(Alert::class)->findBy(['monitoredDomain' => $domainId->toString()]);
        self::assertCount(1, $alerts);
        self::assertSame(AlertType::ManagedDmarcDangling, $alerts[0]->type);
    }

    #[Test]
    public function doesNotCrossMatchReportDmarcAuthorizationRecords(): void
    {
        // Tearing down the policy record must never touch the §7.1 authorization record.
        $domainId = $this->offboardedDomain('acme.example', cnamePointsAtUs: false);
        $this->publisher()->publishAuthorizationRecord('acme.example');

        $this->runSync();

        self::assertFalse($this->publisher()->policyRecordExists('acme.example'), 'Policy record torn down.');
        self::assertTrue($this->publisher()->authorizationRecordExists('acme.example'), 'Authorization record must be left intact.');
    }

    #[Test]
    public function skipsEntirelyWhenCloudflareIsNotConfigured(): void
    {
        $domainId = $this->managedDomain('acme.example', DmarcPolicy::Quarantine);

        $command = new SyncHostedDmarcRecordsCommand(
            new CloudflareDnsClient(new MockHttpClient(), new ReportAddressProvider('reports@sendvery.test'), new NullLogger(), '', ''),
            $this->publisher(),
            $this->composer(),
            $this->getService(ManagedDmarcCnameChecker::class),
            $this->getService(Connection::class),
            $this->getService(EntityManagerInterface::class),
            $this->getService(MessageBusInterface::class),
        );
        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertStringContainsString('not configured', $tester->getDisplay());
        self::assertFalse($this->publisher()->policyRecordExists('acme.example'));
    }

    private function runSync(): void
    {
        $tester = new CommandTester($this->getService(SyncHostedDmarcRecordsCommand::class));
        $tester->execute([]);
        self::assertSame(0, $tester->getStatusCode());
    }

    private function publisher(): FakeDnsRecordPublisher
    {
        return $this->getService(FakeDnsRecordPublisher::class);
    }

    private function composer(): ManagedDmarcPolicyComposer
    {
        return $this->getService(ManagedDmarcPolicyComposer::class);
    }

    private function reload(UuidInterface $domainId): MonitoredDomain
    {
        $this->getService(EntityManagerInterface::class)->clear();

        return $this->getService(MonitoredDomainRepository::class)->get($domainId);
    }

    private function managedDomain(string $name, DmarcPolicy $policy): UuidInterface
    {
        $em = $this->getService(EntityManagerInterface::class);
        $team = new Team(id: Uuid::uuid7(), name: 'Sync', slug: 'sync-'.Uuid::uuid7()->toString(), createdAt: new \DateTimeImmutable(), plan: 'pro');
        $em->persist($team);

        $domainId = Uuid::uuid7();
        $domain = new MonitoredDomain(id: $domainId, team: $team, domain: $name, createdAt: new \DateTimeImmutable());
        $domain->dmarcSetupMode = DmarcSetupMode::ManagedCname;
        $domain->managedPolicyP = $policy;
        $domain->autoRampStage = AutoRampStage::fromPolicy($policy);
        $em->persist($domain);
        $em->flush();

        return $domainId;
    }

    private function offboardedDomain(string $name, bool $cnamePointsAtUs): UuidInterface
    {
        $em = $this->getService(EntityManagerInterface::class);
        $team = new Team(id: Uuid::uuid7(), name: 'Sync', slug: 'sync-'.Uuid::uuid7()->toString(), createdAt: new \DateTimeImmutable(), plan: 'pro');
        $em->persist($team);

        $domainId = Uuid::uuid7();
        $domain = new MonitoredDomain(id: $domainId, team: $team, domain: $name, createdAt: new \DateTimeImmutable());
        $domain->dmarcSetupMode = DmarcSetupMode::SelfTxt;
        $domain->cloudflareHostedDmarcRecordId = $this->publisher()->publishPolicyRecord($name, 'v=DMARC1; p=quarantine; rua=mailto:reports@sendvery.test');
        $domain->hostedDmarcTeardownAt = new \DateTimeImmutable('-1 day');
        $em->persist($domain);
        $em->flush();

        if ($cnamePointsAtUs) {
            $this->scriptDns()->withCname('_dmarc.'.$name, $name.'._dmarc.sendvery.test');
        }

        return $domainId;
    }
}
