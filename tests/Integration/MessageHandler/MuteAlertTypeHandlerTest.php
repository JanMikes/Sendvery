<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\MonitoredDomain;
use App\Entity\MutedAlert;
use App\Entity\Team;
use App\Message\MuteAlertType;
use App\Message\UnmuteAlertType;
use App\MessageHandler\MuteAlertTypeHandler;
use App\MessageHandler\UnmuteAlertTypeHandler;
use App\Repository\MutedAlertRepository;
use App\Tests\IntegrationTestCase;
use App\Value\AlertType;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class MuteAlertTypeHandlerTest extends IntegrationTestCase
{
    public function testPersistsAndFlushesMute(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $handler = $this->getService(MuteAlertTypeHandler::class);
        $repo = $this->getService(MutedAlertRepository::class);

        [$team, $domain] = $this->persistTeamAndDomain($em);

        $mutedAlertId = Uuid::uuid7();

        $handler(new MuteAlertType(
            mutedAlertId: $mutedAlertId,
            teamId: $team->id,
            domainId: $domain->id,
            alertType: AlertType::FailureSpike,
        ));

        // No explicit flush in test — the handler must flush itself because
        // MutedAlert has no EntityWithEvents → postFlush won't fire.
        $em->clear();
        $reloaded = $em->find(MutedAlert::class, $mutedAlertId);
        self::assertNotNull($reloaded);
        self::assertSame(AlertType::FailureSpike, $reloaded->alertType);
        self::assertTrue($repo->isMuted($team->id->toString(), $domain->id->toString(), AlertType::FailureSpike));
    }

    public function testIsIdempotentWhenAlreadyMuted(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $handler = $this->getService(MuteAlertTypeHandler::class);

        [$team, $domain] = $this->persistTeamAndDomain($em);

        $handler(new MuteAlertType(
            mutedAlertId: Uuid::uuid7(),
            teamId: $team->id,
            domainId: $domain->id,
            alertType: AlertType::FailureSpike,
        ));

        // Second mute for the same (team, domain, type) — must be a no-op.
        $handler(new MuteAlertType(
            mutedAlertId: Uuid::uuid7(),
            teamId: $team->id,
            domainId: $domain->id,
            alertType: AlertType::FailureSpike,
        ));

        $em->clear();
        $count = $em->getRepository(MutedAlert::class)->count([
            'team' => $team->id->toString(),
            'monitoredDomain' => $domain->id->toString(),
            'alertType' => AlertType::FailureSpike,
        ]);
        self::assertSame(1, $count);
    }

    public function testUnmuteRemovesAndFlushes(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $muteHandler = $this->getService(MuteAlertTypeHandler::class);
        $unmuteHandler = $this->getService(UnmuteAlertTypeHandler::class);
        $repo = $this->getService(MutedAlertRepository::class);

        [$team, $domain] = $this->persistTeamAndDomain($em);

        $mutedAlertId = Uuid::uuid7();
        $muteHandler(new MuteAlertType(
            mutedAlertId: $mutedAlertId,
            teamId: $team->id,
            domainId: $domain->id,
            alertType: AlertType::FailureSpike,
        ));

        self::assertTrue($repo->isMuted($team->id->toString(), $domain->id->toString(), AlertType::FailureSpike));

        $unmuteHandler(new UnmuteAlertType(mutedAlertId: $mutedAlertId));

        $em->clear();
        self::assertNull($em->find(MutedAlert::class, $mutedAlertId));
        self::assertFalse($repo->isMuted($team->id->toString(), $domain->id->toString(), AlertType::FailureSpike));
    }

    /** @return array{Team, MonitoredDomain} */
    private function persistTeamAndDomain(EntityManagerInterface $em): array
    {
        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Mute Team',
            slug: 'mute-team-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $team->popEvents();
        $em->persist($team);

        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'mute-'.Uuid::uuid7()->toString().'.com',
            createdAt: new \DateTimeImmutable(),
        );
        $domain->popEvents();
        $em->persist($domain);
        $em->flush();

        return [$team, $domain];
    }
}
