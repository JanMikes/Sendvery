<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Events\DmarcPolicyChanged;
use App\Events\ManagedDmarcDisabled;
use App\Events\ManagedDmarcEnabled;
use App\MessageHandler\PublishHostedDmarcRecordWhenManagedEnabled;
use App\MessageHandler\RemoveHostedDmarcRecordWhenManagedDisabled;
use App\MessageHandler\UpdateHostedDmarcRecordWhenPolicyChanged;
use App\Repository\MonitoredDomainRepository;
use App\Services\Dns\FakeDnsRecordPublisher;
use App\Tests\IntegrationTestCase;
use App\Value\DmarcPolicy;
use App\Value\Dns\AutoRampStage;
use App\Value\Dns\DmarcSetupMode;
use App\Value\Dns\ManagedDmarcPolicy;
use App\Value\Dns\PolicyChangeSource;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

final class HostedDmarcRecordEventHandlersTest extends IntegrationTestCase
{
    #[Test]
    public function updateLeavesTheIdUnchangedOnPublishFailure(): void
    {
        $domainId = $this->managedDomain(DmarcPolicy::Quarantine, hostedRecordId: 'kept-id');
        $this->getService(FakeDnsRecordPublisher::class)->simulateFailure();

        $this->updateOnPolicyChange($domainId, new ManagedDmarcPolicy(DmarcPolicy::Reject));

        self::assertSame('kept-id', $this->reload($domainId)->cloudflareHostedDmarcRecordId);
        $this->getService(FakeDnsRecordPublisher::class)->simulateSuccess();
    }

    #[Test]
    public function updateSkipsWhenThePolicyIntentIsCleared(): void
    {
        $domainId = $this->managedDomain(null, hostedRecordId: 'kept-id');

        $this->updateOnPolicyChange($domainId, new ManagedDmarcPolicy(DmarcPolicy::Reject));

        self::assertSame('kept-id', $this->reload($domainId)->cloudflareHostedDmarcRecordId);
    }

    #[Test]
    public function updateAndPublishAndRemoveSkipAMissingDomain(): void
    {
        $missing = Uuid::uuid7();
        $policy = new ManagedDmarcPolicy(DmarcPolicy::Reject);

        $this->getService(UpdateHostedDmarcRecordWhenPolicyChanged::class)(new DmarcPolicyChanged($missing, Uuid::uuid7(), 'gone.example', null, $policy, PolicyChangeSource::AutoRamp, null));
        $this->getService(PublishHostedDmarcRecordWhenManagedEnabled::class)(new ManagedDmarcEnabled($missing, Uuid::uuid7(), 'gone.example'));
        $this->getService(RemoveHostedDmarcRecordWhenManagedDisabled::class)(new ManagedDmarcDisabled($missing, Uuid::uuid7(), 'gone.example', 'some-id'));

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function publishSkipsWhenThePolicyIntentIsCleared(): void
    {
        $domainId = $this->managedDomain(null, hostedRecordId: null);

        $this->getService(PublishHostedDmarcRecordWhenManagedEnabled::class)(new ManagedDmarcEnabled($domainId, $this->teamIdFor($domainId), 'acme.example'));
        $this->getService(EntityManagerInterface::class)->flush();

        self::assertNull($this->reload($domainId)->cloudflareHostedDmarcRecordId);
    }

    private function updateOnPolicyChange(UuidInterface $domainId, ManagedDmarcPolicy $to): void
    {
        $this->getService(UpdateHostedDmarcRecordWhenPolicyChanged::class)(
            new DmarcPolicyChanged($domainId, $this->teamIdFor($domainId), 'acme.example', null, $to, PolicyChangeSource::AutoRamp, null),
        );
        $this->getService(EntityManagerInterface::class)->flush();
    }

    private function managedDomain(?DmarcPolicy $p, ?string $hostedRecordId): UuidInterface
    {
        $em = $this->getService(EntityManagerInterface::class);

        $team = new Team(id: Uuid::uuid7(), name: 'Hosted Events', slug: 'hosted-events-'.Uuid::uuid7()->toString(), createdAt: new \DateTimeImmutable(), plan: 'pro');
        $team->popEvents();
        $em->persist($team);

        $domainId = Uuid::uuid7();
        $entity = new MonitoredDomain(id: $domainId, team: $team, domain: 'acme.example', createdAt: new \DateTimeImmutable());
        $entity->dmarcSetupMode = DmarcSetupMode::ManagedCname;
        $entity->managedPolicyP = $p;
        $entity->autoRampStage = AutoRampStage::fromPolicy($p);
        $entity->cloudflareHostedDmarcRecordId = $hostedRecordId;
        $entity->popEvents();
        $em->persist($entity);
        $em->flush();

        return $domainId;
    }

    private function reload(UuidInterface $domainId): MonitoredDomain
    {
        return $this->getService(MonitoredDomainRepository::class)->get($domainId);
    }

    private function teamIdFor(UuidInterface $domainId): UuidInterface
    {
        return $this->reload($domainId)->team->id;
    }
}
