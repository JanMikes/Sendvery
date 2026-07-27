<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\Alert;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Events\DnsCheckCompleted;
use App\MessageHandler\AlertOnDnsChange;
use App\Tests\IntegrationTestCase;
use App\Value\AlertSeverity;
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
    public function suppressesDmarcChangeAlertsForManagedDomains(): void
    {
        [$team, $domain] = $this->createTeamAndDomain();
        $em = $this->getService(EntityManagerInterface::class);
        $domain->dmarcSetupMode = \App\Value\Dns\DmarcSetupMode::ManagedCname;
        $em->flush();

        // Sendvery ramped the hosted policy → the DMARC record "changed". This must
        // NOT raise a generic DnsRecordChanged alert for a domain we manage.
        $this->getService(AlertOnDnsChange::class)(new DnsCheckCompleted(
            dnsCheckResultId: Uuid::uuid7(),
            domainId: $domain->id,
            teamId: $team->id,
            type: DnsCheckType::Dmarc,
            hasChanged: true,
            isValid: true,
            rawRecord: 'v=DMARC1; p=quarantine; rua=mailto:reports@sendvery.test',
            previousRawRecord: 'v=DMARC1; p=none; rua=mailto:reports@sendvery.test',
        ));
        $em->flush();

        self::assertCount(0, $em->getRepository(Alert::class)->findBy(['team' => $team->id->toString()]));

        // A self-TXT domain (SPF here) still alerts normally.
        $this->getService(AlertOnDnsChange::class)(new DnsCheckCompleted(
            dnsCheckResultId: Uuid::uuid7(),
            domainId: $domain->id,
            teamId: $team->id,
            type: DnsCheckType::Spf,
            hasChanged: true,
            isValid: true,
            rawRecord: 'v=spf1 include:new ~all',
            previousRawRecord: 'v=spf1 ~all',
        ));
        $em->flush();

        self::assertCount(1, $em->getRepository(Alert::class)->findBy(['team' => $team->id->toString()]));
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
        // A real edit of an already-published record still deserves the yellow
        // "was this you?" treatment — only nothing→valid gets the green path.
        self::assertSame(AlertSeverity::Warning, $alerts[0]->severity);
    }

    #[Test]
    public function publishingARecordForTheFirstTimeIsReportedAsGoodNewsNotAWarning(): void
    {
        // Nothing → valid is the successful completion of the user's own setup.
        // Reporting it in the same yellow as "record changed, review it" made
        // users read their own success as a fault.
        [$team, $domain] = $this->createTeamAndDomain();
        $em = $this->getService(EntityManagerInterface::class);
        $handler = $this->getService(AlertOnDnsChange::class);

        $handler(new DnsCheckCompleted(
            dnsCheckResultId: Uuid::uuid7(),
            domainId: $domain->id,
            teamId: $team->id,
            type: DnsCheckType::Dkim,
            hasChanged: true,
            isValid: true,
            rawRecord: 'v=DKIM1; k=rsa; p=MIGf',
            previousRawRecord: null,
        ));
        $em->flush();

        $alerts = $em->getRepository(Alert::class)->findBy(['team' => $team->id->toString()]);
        self::assertCount(1, $alerts);
        self::assertSame(AlertType::DnsRecordPublished, $alerts[0]->type);
        self::assertSame(AlertSeverity::Success, $alerts[0]->severity, 'A first-time publication is good news, so it must be green — never a yellow warning.');
        self::assertStringContainsString('published for dns-alert-test.com', $alerts[0]->title);
        self::assertStringContainsString('no action is needed', $alerts[0]->message);
    }

    #[Test]
    public function anEmptyPreviousRecordCountsAsNeverPublished(): void
    {
        // A blank/whitespace previous value is "not published" as far as the
        // user is concerned — it must take the same green path as a null one.
        [$team, $domain] = $this->createTeamAndDomain();
        $em = $this->getService(EntityManagerInterface::class);
        $handler = $this->getService(AlertOnDnsChange::class);

        $handler(new DnsCheckCompleted(
            dnsCheckResultId: Uuid::uuid7(),
            domainId: $domain->id,
            teamId: $team->id,
            type: DnsCheckType::Spf,
            hasChanged: true,
            isValid: true,
            rawRecord: 'v=spf1 ~all',
            previousRawRecord: '   ',
        ));
        $em->flush();

        $alerts = $em->getRepository(Alert::class)->findBy(['team' => $team->id->toString()]);
        self::assertCount(1, $alerts);
        self::assertSame(AlertType::DnsRecordPublished, $alerts[0]->type);
        self::assertSame(AlertSeverity::Success, $alerts[0]->severity);
    }

    #[Test]
    public function firesAlertOnFirstCheckWhenAnExistingRecordIsBroken(): void
    {
        // Domain added with a pre-existing misconfiguration — a record IS
        // published but fails validation. Without first-check alerting this
        // would silently sit in the dashboard until something else changed.
        [$team, $domain] = $this->createTeamAndDomain();
        $em = $this->getService(EntityManagerInterface::class);
        $handler = $this->getService(AlertOnDnsChange::class);

        $event = new DnsCheckCompleted(
            dnsCheckResultId: Uuid::uuid7(),
            domainId: $domain->id,
            teamId: $team->id,
            type: DnsCheckType::Spf,
            hasChanged: false,
            isValid: false,
            rawRecord: 'v=spf1 include:a include:b include:c include:d include:e include:f include:g include:h include:i include:j include:k -all',
            previousRawRecord: null,
            isFirstCheck: true,
        );

        $handler($event);
        $em->flush();

        $alerts = $em->getRepository(Alert::class)->findBy(['team' => $team->id->toString()]);
        self::assertCount(1, $alerts);
        self::assertSame(AlertType::DnsRecordInvalid, $alerts[0]->type);
        self::assertStringContainsString('broken for dns-alert-test.com', $alerts[0]->title);
    }

    #[Test]
    public function noAlertOnFirstCheckWhenTheRecordIsSimplyMissing(): void
    {
        // A record that was never published is a setup task, not an incident —
        // freshly added domains are usually mid-setup, and a critical
        // "X is broken" email for every unpublished record floods the user
        // moments after they add a domain. The setup checklist owns this state.
        [$team, $domain] = $this->createTeamAndDomain();
        $em = $this->getService(EntityManagerInterface::class);
        $handler = $this->getService(AlertOnDnsChange::class);

        $event = new DnsCheckCompleted(
            dnsCheckResultId: Uuid::uuid7(),
            domainId: $domain->id,
            teamId: $team->id,
            type: DnsCheckType::Dkim,
            hasChanged: false,
            isValid: false,
            rawRecord: null,
            previousRawRecord: null,
            isFirstCheck: true,
        );

        $handler($event);
        $em->flush();

        $alerts = $em->getRepository(Alert::class)->findBy(['team' => $team->id->toString()]);
        self::assertCount(0, $alerts);
    }

    #[Test]
    public function noAlertOnFirstCheckWhenHealthy(): void
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
            previousRawRecord: null,
            isFirstCheck: true,
        );

        $handler($event);
        $em->flush();

        $alerts = $em->getRepository(Alert::class)->findBy(['team' => $team->id->toString()]);
        self::assertCount(0, $alerts);
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
