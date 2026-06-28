<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Exceptions\ManagedDmarcNotAvailable;
use App\Message\ConfigureDmarcAutoRamp;
use App\MessageHandler\ConfigureDmarcAutoRampHandler;
use App\Repository\MonitoredDomainRepository;
use App\Tests\IntegrationTestCase;
use App\Value\DmarcPolicy;
use App\Value\Dns\AutoRampAction;
use App\Value\Dns\AutoRampStage;
use App\Value\Dns\DmarcSetupMode;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

final class ConfigureDmarcAutoRampHandlerTest extends IntegrationTestCase
{
    #[Test]
    public function enablesPausesResumesAndDisablesAutoRamp(): void
    {
        $domainId = $this->managedDomain(plan: 'pro');

        $this->handle(new ConfigureDmarcAutoRamp($domainId, $this->teamIdFor($domainId), AutoRampAction::Enable));
        self::assertTrue($this->reload($domainId)->autoRampEnabled);

        $this->handle(new ConfigureDmarcAutoRamp($domainId, $this->teamIdFor($domainId), AutoRampAction::Pause));
        self::assertNotNull($this->reload($domainId)->autoRampPausedAt);

        $this->handle(new ConfigureDmarcAutoRamp($domainId, $this->teamIdFor($domainId), AutoRampAction::Resume));
        self::assertNull($this->reload($domainId)->autoRampPausedAt);

        $this->handle(new ConfigureDmarcAutoRamp($domainId, $this->teamIdFor($domainId), AutoRampAction::Disable));
        self::assertFalse($this->reload($domainId)->autoRampEnabled);
    }

    #[Test]
    public function turningAutoDriveOnIsHardRefusedOnFree(): void
    {
        $domainId = $this->managedDomain(plan: 'free');

        $this->expectException(ManagedDmarcNotAvailable::class);
        $this->handle(new ConfigureDmarcAutoRamp($domainId, $this->teamIdFor($domainId), AutoRampAction::Enable));
    }

    #[Test]
    public function turningAutoDriveOffIsAllowedEvenWhenFrozen(): void
    {
        // A downgraded (frozen) team must still be able to turn auto-drive off.
        $domainId = $this->managedDomain(plan: 'free', autoRampEnabled: true);

        $this->handle(new ConfigureDmarcAutoRamp($domainId, $this->teamIdFor($domainId), AutoRampAction::Disable));

        self::assertFalse($this->reload($domainId)->autoRampEnabled);
    }

    #[Test]
    public function rejectsAForgedRequestForAnotherTeamsDomain(): void
    {
        $domainId = $this->managedDomain(plan: 'pro');

        $this->expectException(\RuntimeException::class);
        $this->handle(new ConfigureDmarcAutoRamp($domainId, Uuid::uuid7()->toString(), AutoRampAction::Disable));
    }

    private function handle(ConfigureDmarcAutoRamp $message): void
    {
        $this->getService(ConfigureDmarcAutoRampHandler::class)($message);
        $this->getService(EntityManagerInterface::class)->flush();
    }

    private function reload(UuidInterface $domainId): MonitoredDomain
    {
        return $this->getService(MonitoredDomainRepository::class)->get($domainId);
    }

    private function managedDomain(string $plan, bool $autoRampEnabled = false): UuidInterface
    {
        $em = $this->getService(EntityManagerInterface::class);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Auto Ramp',
            slug: 'auto-ramp-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
            plan: $plan,
        );
        $team->popEvents();
        $em->persist($team);

        $domainId = Uuid::uuid7();
        $entity = new MonitoredDomain(id: $domainId, team: $team, domain: 'acme.example', createdAt: new \DateTimeImmutable());
        $entity->dmarcSetupMode = DmarcSetupMode::ManagedCname;
        $entity->managedPolicyP = DmarcPolicy::None;
        $entity->autoRampStage = AutoRampStage::Monitoring;
        $entity->autoRampEnabled = $autoRampEnabled;
        $entity->popEvents();
        $em->persist($entity);
        $em->flush();

        return $domainId;
    }

    private function teamIdFor(UuidInterface $domainId): string
    {
        return $this->reload($domainId)->team->id->toString();
    }
}
