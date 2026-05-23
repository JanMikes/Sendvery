<?php

declare(strict_types=1);

namespace App\Tests\Integration\Services;

use App\Entity\Alert;
use App\Entity\MonitoredDomain;
use App\Entity\MutedAlert;
use App\Entity\Team;
use App\Services\AlertEngine;
use App\Services\IdentityProvider;
use App\Tests\IntegrationTestCase;
use App\Value\AlertSeverity;
use App\Value\AlertType;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class AlertEngineMuteTest extends IntegrationTestCase
{
    public function testCreatesAlertWhenNotMuted(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $engine = $this->getService(AlertEngine::class);

        [$team, $domain] = $this->persistTeamAndDomain($em);

        $alert = $engine->createAlert(
            team: $team,
            monitoredDomain: $domain,
            type: AlertType::FailureSpike,
            severity: AlertSeverity::Warning,
            title: 'Spike',
            message: 'msg',
        );

        self::assertNotNull($alert);
        $em->flush();

        $em->clear();
        $reloaded = $em->find(Alert::class, $alert->id);
        self::assertNotNull($reloaded);
    }

    public function testReturnsNullAndSkipsPersistWhenMuted(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $engine = $this->getService(AlertEngine::class);
        $identity = $this->getService(IdentityProvider::class);

        [$team, $domain] = $this->persistTeamAndDomain($em);

        $muted = new MutedAlert(
            id: $identity->nextIdentity(),
            team: $team,
            monitoredDomain: $domain,
            alertType: AlertType::FailureSpike,
            mutedAt: new \DateTimeImmutable(),
        );
        $em->persist($muted);
        $em->flush();

        $alert = $engine->createAlert(
            team: $team,
            monitoredDomain: $domain,
            type: AlertType::FailureSpike,
            severity: AlertSeverity::Warning,
            title: 'Spike',
            message: 'msg',
        );

        self::assertNull($alert);
        $em->flush();

        // No alert row should have been persisted for this team.
        $em->clear();
        $alerts = $em->getRepository(Alert::class)->findBy(['team' => $team->id->toString()]);
        self::assertCount(0, $alerts);
    }

    public function testMuteIsScopedPerType(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $engine = $this->getService(AlertEngine::class);
        $identity = $this->getService(IdentityProvider::class);

        [$team, $domain] = $this->persistTeamAndDomain($em);

        $muted = new MutedAlert(
            id: $identity->nextIdentity(),
            team: $team,
            monitoredDomain: $domain,
            alertType: AlertType::FailureSpike,
            mutedAt: new \DateTimeImmutable(),
        );
        $em->persist($muted);
        $em->flush();

        // A different alert type is NOT muted — must still emit.
        $alert = $engine->createAlert(
            team: $team,
            monitoredDomain: $domain,
            type: AlertType::DnsRecordChanged,
            severity: AlertSeverity::Warning,
            title: 'DNS',
            message: 'msg',
        );

        self::assertNotNull($alert);
    }

    public function testDomainLessAlertsBypassMuteCheck(): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $engine = $this->getService(AlertEngine::class);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Mute Engine',
            slug: 'mute-engine-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $team->popEvents();
        $em->persist($team);
        $em->flush();

        $alert = $engine->createAlert(
            team: $team,
            monitoredDomain: null,
            type: AlertType::MailboxConnectionError,
            severity: AlertSeverity::Critical,
            title: 'mailbox',
            message: 'msg',
        );

        self::assertNotNull($alert);
    }

    /**
     * @return array{Team, MonitoredDomain}
     */
    private function persistTeamAndDomain(EntityManagerInterface $em): array
    {
        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Engine Mute',
            slug: 'engine-mute-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $team->popEvents();
        $em->persist($team);

        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'engine-mute-'.Uuid::uuid7()->toString().'.com',
            createdAt: new \DateTimeImmutable(),
        );
        $domain->popEvents();
        $em->persist($domain);
        $em->flush();

        return [$team, $domain];
    }
}
