<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Exceptions\ManagedDmarcNotAvailable;
use App\Message\EnableManagedDmarc;
use App\MessageHandler\EnableManagedDmarcHandler;
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

final class EnableManagedDmarcHandlerTest extends IntegrationTestCase
{
    use ScriptsDnsRecords;

    #[Test]
    public function publishesTheSeededHostedTxtAndStoresItsId(): void
    {
        $domainId = $this->createDomain('acme.example', plan: 'pro');

        $this->handle(new EnableManagedDmarc($domainId, $this->teamIdFor($domainId), null));

        $domain = $this->getService(MonitoredDomainRepository::class)->get($domainId);
        self::assertSame(DmarcSetupMode::ManagedCname, $domain->dmarcSetupMode);
        self::assertSame(DmarcPolicy::None, $domain->managedPolicyP, 'No live enforcing policy → seed monitoring.');
        self::assertNotNull($domain->cloudflareHostedDmarcRecordId);

        $content = $this->getService(FakeDnsRecordPublisher::class)->getPublishedPolicyContent('acme.example');
        self::assertNotNull($content);
        self::assertStringContainsString('p=none', $content);
        self::assertStringContainsString('rua=mailto:reports@sendvery.test', $content);
    }

    #[Test]
    public function preservesAnExistingQuarantinePolicyOnSwitchover(): void
    {
        $this->scriptDns()->withTxt('_dmarc.acme.example', 'v=DMARC1; p=quarantine; rua=mailto:old@acme.example');
        $domainId = $this->createDomain('acme.example', plan: 'pro');

        $this->handle(new EnableManagedDmarc($domainId, $this->teamIdFor($domainId), null));

        $domain = $this->getService(MonitoredDomainRepository::class)->get($domainId);
        self::assertSame(DmarcPolicy::Quarantine, $domain->managedPolicyP, 'Switchover must preserve the live enforcement strength.');
        self::assertSame(AutoRampStage::Quarantine, $domain->autoRampStage);

        $content = $this->getService(FakeDnsRecordPublisher::class)->getPublishedPolicyContent('acme.example');
        self::assertNotNull($content);
        self::assertStringContainsString('p=quarantine', $content);
        // rua becomes Sendvery-only (DEC-058a) — the customer's old rua is dropped.
        self::assertStringContainsString('rua=mailto:reports@sendvery.test', $content);
        self::assertStringNotContainsString('old@acme.example', $content);
    }

    #[Test]
    public function marksCnameVerifiedWhenItAlreadyPointsAtUs(): void
    {
        // A customer who pre-pointed the CNAME before enabling — verify immediately.
        $this->scriptDns()->withCname('_dmarc.acme.example', 'acme.example._dmarc.sendvery.test');
        $domainId = $this->createDomain('acme.example', plan: 'pro');

        $this->handle(new EnableManagedDmarc($domainId, $this->teamIdFor($domainId), null));

        $domain = $this->getService(MonitoredDomainRepository::class)->get($domainId);
        self::assertNotNull($domain->cnameVerifiedAt, 'A pre-pointed CNAME must be marked verified at enable time.');
    }

    #[Test]
    public function rejectsAForgedRequestForAnotherTeamsDomain(): void
    {
        $domainId = $this->createDomain('acme.example', plan: 'pro');

        $this->expectException(\RuntimeException::class);
        $this->handle(new EnableManagedDmarc($domainId, Uuid::uuid7()->toString(), null));
    }

    #[Test]
    public function hardRefusesAFreePlanTeam(): void
    {
        $domainId = $this->createDomain('acme.example', plan: 'free');

        $this->expectException(ManagedDmarcNotAvailable::class);
        $this->handle(new EnableManagedDmarc($domainId, $this->teamIdFor($domainId), null));
    }

    private function handle(EnableManagedDmarc $message): void
    {
        $this->getService(EnableManagedDmarcHandler::class)($message);
        $this->getService(EntityManagerInterface::class)->flush();
    }

    private function createDomain(string $domain, string $plan): UuidInterface
    {
        $em = $this->getService(EntityManagerInterface::class);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Managed Enable',
            slug: 'managed-enable-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
            plan: $plan,
        );
        $team->popEvents();
        $em->persist($team);

        $domainId = Uuid::uuid7();
        $entity = new MonitoredDomain(id: $domainId, team: $team, domain: $domain, createdAt: new \DateTimeImmutable());
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
