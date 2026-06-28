<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Exceptions\ManagedDmarcNotAvailable;
use App\Message\SetDmarcPolicy;
use App\MessageHandler\SetDmarcPolicyHandler;
use App\Repository\MonitoredDomainRepository;
use App\Services\Dns\FakeDnsRecordPublisher;
use App\Tests\IntegrationTestCase;
use App\Value\DmarcPolicy;
use App\Value\Dns\AutoRampStage;
use App\Value\Dns\DmarcSetupMode;
use App\Value\Dns\PolicyChangeSource;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

final class SetDmarcPolicyHandlerTest extends IntegrationTestCase
{
    #[Test]
    public function publishingQuarantineUpdatesTheHostedTxtContent(): void
    {
        $domainId = $this->managedDomain('acme.example', plan: 'pro', p: DmarcPolicy::None);

        $this->handle(new SetDmarcPolicy($domainId, $this->teamIdFor($domainId), null, DmarcPolicy::Quarantine, null, 100));

        $domain = $this->getService(MonitoredDomainRepository::class)->get($domainId);
        self::assertSame(DmarcPolicy::Quarantine, $domain->managedPolicyP);
        self::assertSame(AutoRampStage::Quarantine, $domain->autoRampStage);

        $content = $this->getService(FakeDnsRecordPublisher::class)->getPublishedPolicyContent('acme.example');
        self::assertNotNull($content);
        self::assertStringContainsString('p=quarantine', $content);
    }

    #[Test]
    public function aManualChangeOnAFreePlanIsHardRefused(): void
    {
        $domainId = $this->managedDomain('acme.example', plan: 'free', p: DmarcPolicy::None);

        $this->expectException(ManagedDmarcNotAvailable::class);
        $this->handle(new SetDmarcPolicy($domainId, $this->teamIdFor($domainId), null, DmarcPolicy::Quarantine, null, 100, PolicyChangeSource::Manual));
    }

    #[Test]
    public function aSystemRollbackIsAllowedEvenWithoutEntitlement(): void
    {
        // A frozen (downgraded) team must still be able to be loosened for safety.
        $domainId = $this->managedDomain('acme.example', plan: 'free', p: DmarcPolicy::Reject);

        $this->handle(new SetDmarcPolicy($domainId, $this->teamIdFor($domainId), null, DmarcPolicy::Quarantine, null, 100, PolicyChangeSource::Rollback));

        $domain = $this->getService(MonitoredDomainRepository::class)->get($domainId);
        self::assertSame(DmarcPolicy::Quarantine, $domain->managedPolicyP);
    }

    #[Test]
    public function rejectsAForgedRequestForAnotherTeamsDomain(): void
    {
        $domainId = $this->managedDomain('acme.example', plan: 'pro', p: DmarcPolicy::None);

        $this->expectException(\RuntimeException::class);
        $this->handle(new SetDmarcPolicy($domainId, Uuid::uuid7()->toString(), null, DmarcPolicy::Quarantine, null, 100));
    }

    private function handle(SetDmarcPolicy $message): void
    {
        $this->getService(SetDmarcPolicyHandler::class)($message);
        $this->getService(EntityManagerInterface::class)->flush();
    }

    private function managedDomain(string $domain, string $plan, DmarcPolicy $p): UuidInterface
    {
        $em = $this->getService(EntityManagerInterface::class);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Set Policy',
            slug: 'set-policy-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
            plan: $plan,
        );
        $team->popEvents();
        $em->persist($team);

        $domainId = Uuid::uuid7();
        $entity = new MonitoredDomain(id: $domainId, team: $team, domain: $domain, createdAt: new \DateTimeImmutable('-30 days'));
        $entity->dmarcSetupMode = DmarcSetupMode::ManagedCname;
        $entity->managedPolicyP = $p;
        $entity->autoRampStage = AutoRampStage::fromPolicy($p);
        $entity->managedDmarcEnabledAt = new \DateTimeImmutable('-20 days');
        $entity->cnameVerifiedAt = new \DateTimeImmutable('-19 days');
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
