<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Message\SetDmarcPolicy;
use App\MessageHandler\SetDmarcPolicyHandler;
use App\Query\GetManagedDmarcPolicyHistory;
use App\Tests\IntegrationTestCase;
use App\Value\DmarcPolicy;
use App\Value\Dns\AutoRampStage;
use App\Value\Dns\DmarcSetupMode;
use App\Value\Dns\PolicyChangeSource;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;

final class RecordManagedDmarcPolicyChangeTest extends IntegrationTestCase
{
    #[Test]
    public function writesAnAuditRowWithActorAndSource(): void
    {
        $em = $this->getService(EntityManagerInterface::class);

        $team = new Team(id: Uuid::uuid7(), name: 'Audit', slug: 'audit-'.Uuid::uuid7()->toString(), createdAt: new \DateTimeImmutable(), plan: 'pro');
        $team->popEvents();
        $em->persist($team);

        $domainId = Uuid::uuid7();
        $domain = new MonitoredDomain(id: $domainId, team: $team, domain: 'acme.example', createdAt: new \DateTimeImmutable());
        $domain->dmarcSetupMode = DmarcSetupMode::ManagedCname;
        $domain->managedPolicyP = DmarcPolicy::None;
        $domain->autoRampStage = AutoRampStage::Monitoring;
        $domain->popEvents();
        $em->persist($domain);
        $em->flush();

        $actorId = Uuid::uuid7();
        $this->getService(SetDmarcPolicyHandler::class)(
            new SetDmarcPolicy($domainId, $team->id->toString(), $actorId, DmarcPolicy::Quarantine, null, 100),
        );
        $em->flush();

        $history = (new GetManagedDmarcPolicyHistory($em->getConnection()))->forDomain($domainId, [$team->id]);

        self::assertCount(1, $history);
        self::assertSame('none', $history[0]->fromPolicy);
        self::assertSame('quarantine', $history[0]->toPolicy);
        self::assertSame(PolicyChangeSource::Manual, $history[0]->source);
        self::assertSame($actorId->toString(), $history[0]->actorUserId);
    }

    #[Test]
    public function historyIsEmptyForADomainWithNoChanges(): void
    {
        $em = $this->getService(EntityManagerInterface::class);

        self::assertSame([], (new GetManagedDmarcPolicyHistory($em->getConnection()))->forDomain(Uuid::uuid7(), []));
    }

    #[Test]
    public function skipsAMissingDomainGracefully(): void
    {
        $this->getService(\App\MessageHandler\RecordManagedDmarcPolicyChange::class)(
            new \App\Events\DmarcPolicyChanged(
                Uuid::uuid7(),
                Uuid::uuid7(),
                'gone.example',
                null,
                new \App\Value\Dns\ManagedDmarcPolicy(DmarcPolicy::Quarantine),
                PolicyChangeSource::AutoRamp,
                null,
            ),
        );

        $this->expectNotToPerformAssertions();
    }
}
