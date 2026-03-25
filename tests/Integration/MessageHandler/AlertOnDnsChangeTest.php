<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\Alert;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Events\DnsCheckCompleted;
use App\MessageHandler\AlertOnDnsChange;
use App\Tests\IntegrationTestCase;
use App\Value\AlertType;
use App\Value\DnsCheckType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;

final class AlertOnDnsChangeTest extends IntegrationTestCase
{
    /** @return array{Team, MonitoredDomain} */
    private function createTeamAndDomain(): array
    {
        $em = $this->getService(EntityManagerInterface::class);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'DNS Alert Test',
            slug: 'dns-alert-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $team->popEvents();
        $em->persist($team);

        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'dns-alert-test.com',
            createdAt: new \DateTimeImmutable(),
        );
        $domain->popEvents();
        $em->persist($domain);
        $em->flush();

        return [$team, $domain];
    }

    #[Test]
    public function createsMissingAlertWhenRecordDisappears(): void
    {
        [$team, $domain] = $this->createTeamAndDomain();
        $em = $this->getService(EntityManagerInterface::class);
        $handler = $this->getService(AlertOnDnsChange::class);

        $event = new DnsCheckCompleted(
            dnsCheckResultId: Uuid::uuid7(),
            domainId: $domain->id,
            teamId: $team->id,
            type: DnsCheckType::Spf,
            hasChanged: true,
            isValid: false,
            rawRecord: null,
            previousRawRecord: 'v=spf1 ~all',
        );

        $handler($event);
        $em->flush();

        $alerts = $em->getRepository(Alert::class)->findBy(['team' => $team->id->toString()]);
        self::assertCount(1, $alerts);
        self::assertSame(AlertType::DnsRecordMissing, $alerts[0]->type);
    }

    #[Test]
    public function createsInvalidAlertWhenRecordIsInvalid(): void
    {
        [$team, $domain] = $this->createTeamAndDomain();
        $em = $this->getService(EntityManagerInterface::class);
        $handler = $this->getService(AlertOnDnsChange::class);

        $event = new DnsCheckCompleted(
            dnsCheckResultId: Uuid::uuid7(),
            domainId: $domain->id,
            teamId: $team->id,
            type: DnsCheckType::Dmarc,
            hasChanged: true,
            isValid: false,
            rawRecord: 'v=DMARC1; broken',
            previousRawRecord: 'v=DMARC1; p=reject',
        );

        $handler($event);
        $em->flush();

        $alerts = $em->getRepository(Alert::class)->findBy(['team' => $team->id->toString()]);
        self::assertCount(1, $alerts);
        self::assertSame(AlertType::DnsRecordInvalid, $alerts[0]->type);
    }

    #[Test]
    public function createsChangedAlertForValidChange(): void
    {
        [$team, $domain] = $this->createTeamAndDomain();
        $em = $this->getService(EntityManagerInterface::class);
        $handler = $this->getService(AlertOnDnsChange::class);

        $event = new DnsCheckCompleted(
            dnsCheckResultId: Uuid::uuid7(),
            domainId: $domain->id,
            teamId: $team->id,
            type: DnsCheckType::Spf,
            hasChanged: true,
            isValid: true,
            rawRecord: 'v=spf1 include:new.com ~all',
            previousRawRecord: 'v=spf1 include:old.com ~all',
        );

        $handler($event);
        $em->flush();

        $alerts = $em->getRepository(Alert::class)->findBy(['team' => $team->id->toString()]);
        self::assertCount(1, $alerts);
        self::assertSame(AlertType::DnsRecordChanged, $alerts[0]->type);
    }

    #[Test]
    public function noAlertWhenNoChange(): void
    {
        [$team, $domain] = $this->createTeamAndDomain();
        $em = $this->getService(EntityManagerInterface::class);
        $handler = $this->getService(AlertOnDnsChange::class);

        $event = new DnsCheckCompleted(
            dnsCheckResultId: Uuid::uuid7(),
            domainId: $domain->id,
            teamId: $team->id,
            type: DnsCheckType::Spf,
            hasChanged: false,
            isValid: true,
            rawRecord: 'v=spf1 ~all',
            previousRawRecord: 'v=spf1 ~all',
        );

        $handler($event);
        $em->flush();

        $alerts = $em->getRepository(Alert::class)->findBy(['team' => $team->id->toString()]);
        self::assertCount(0, $alerts);
    }
}
