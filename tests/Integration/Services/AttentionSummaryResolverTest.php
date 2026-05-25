<?php

declare(strict_types=1);

namespace App\Tests\Integration\Services;

use App\Entity\Alert;
use App\Entity\MonitoredDomain;
use App\Entity\QuarantinedDmarcReport;
use App\Entity\ReceivedReportEmail;
use App\Entity\Team;
use App\Services\AttentionSummaryResolver;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use App\Value\AlertSeverity;
use App\Value\AlertType;
use App\Value\Reports\QuarantineReason;
use App\Value\Reports\ReportSource;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;

/**
 * Drives {@see AttentionSummaryResolver} through the 5 acceptance branches
 * (zero / only-criticals / only-unverified / only-quarantine / all-three).
 *
 * Lives in Integration/ rather than Unit/ because the three count sources
 * the resolver injects ({@see \App\Query\GetAlerts},
 * {@see \App\Query\GetQuarantineList}, {@see \App\Query\GetDomainOverview})
 * are `final readonly` per the project convention and therefore cannot be
 * doubled by PHPUnit. Seeding a real DB row per branch is fast enough
 * (single-digit ms per case under DAMA transactions) and exercises the
 * actual SQL each query issues — which is the contract we care about.
 */
final class AttentionSummaryResolverTest extends WebTestCase
{
    #[Test]
    public function zeroCountsProducesEmptyItemsAndZeroTotal(): void
    {
        $persona = $this->bootPersonaWithoutDomain();

        $result = $this->getService(AttentionSummaryResolver::class)
            ->resolveForTeam($persona->team->id->toString());

        self::assertSame(0, $result->criticalAlertCount);
        self::assertSame(0, $result->unverifiedDomainCount);
        self::assertSame(0, $result->quarantineCount);
        self::assertSame(0, $result->totalCount);
        self::assertSame([], $result->items);
    }

    #[Test]
    public function onlyCriticalAlertsProducesOneItem(): void
    {
        $persona = $this->bootPersonaWithoutDomain();
        $em = $this->getService(EntityManagerInterface::class);

        $this->persistAlert($em, $persona->team, AlertSeverity::Critical);
        $this->persistAlert($em, $persona->team, AlertSeverity::Critical);
        $this->persistAlert($em, $persona->team, AlertSeverity::Critical);
        $em->flush();

        $result = $this->getService(AttentionSummaryResolver::class)
            ->resolveForTeam($persona->team->id->toString());

        self::assertSame(3, $result->criticalAlertCount);
        self::assertSame(3, $result->totalCount);
        self::assertCount(1, $result->items);
        self::assertSame('3 critical alerts', $result->items[0]->label);
        self::assertSame('dashboard_alerts', $result->items[0]->route);
        self::assertSame(['severity' => 'critical', 'isRead' => '0'], $result->items[0]->routeParams);
        self::assertSame('text-error', $result->items[0]->colorClass);
    }

    #[Test]
    public function onlyUnverifiedDomainsProducesOneItem(): void
    {
        $persona = $this->bootPersonaWithoutDomain();
        $em = $this->getService(EntityManagerInterface::class);

        $this->persistDomain($em, $persona->team, dmarcVerifiedAt: null);
        $this->persistDomain($em, $persona->team, dmarcVerifiedAt: null);
        $em->flush();

        $result = $this->getService(AttentionSummaryResolver::class)
            ->resolveForTeam($persona->team->id->toString());

        self::assertSame(2, $result->unverifiedDomainCount);
        self::assertSame(2, $result->totalCount);
        self::assertCount(1, $result->items);
        self::assertSame('2 unverified domains', $result->items[0]->label);
        self::assertSame('dashboard_domains', $result->items[0]->route);
        self::assertSame(['status' => 'unverified'], $result->items[0]->routeParams);
        self::assertSame('text-warning', $result->items[0]->colorClass);
    }

    #[Test]
    public function onlyQuarantineProducesOneItem(): void
    {
        $persona = $this->bootPersonaWithoutDomain();
        $em = $this->getService(EntityManagerInterface::class);

        // Quarantine visibility requires a matching monitored_domain for the
        // team — seed one verified domain (so it doesn't count as unverified)
        // and quarantine a report against its name.
        $domain = $this->persistDomain($em, $persona->team, dmarcVerifiedAt: new \DateTimeImmutable());
        $this->persistQuarantined($em, $domain->domain);
        $em->flush();

        $result = $this->getService(AttentionSummaryResolver::class)
            ->resolveForTeam($persona->team->id->toString());

        self::assertSame(0, $result->unverifiedDomainCount);
        self::assertSame(1, $result->quarantineCount);
        self::assertSame(1, $result->totalCount);
        self::assertCount(1, $result->items);
        self::assertSame('1 report in quarantine', $result->items[0]->label);
        self::assertSame('dashboard_quarantine', $result->items[0]->route);
        self::assertSame([], $result->items[0]->routeParams);
        self::assertSame('text-warning', $result->items[0]->colorClass);
    }

    #[Test]
    public function allThreeSignalsProduceSeverityOrderedItems(): void
    {
        $persona = $this->bootPersonaWithoutDomain();
        $em = $this->getService(EntityManagerInterface::class);

        $this->persistAlert($em, $persona->team, AlertSeverity::Critical);
        $verifiedDomain = $this->persistDomain($em, $persona->team, dmarcVerifiedAt: new \DateTimeImmutable());
        $this->persistDomain($em, $persona->team, dmarcVerifiedAt: null);
        $this->persistDomain($em, $persona->team, dmarcVerifiedAt: null);
        $this->persistQuarantined($em, $verifiedDomain->domain);
        $em->flush();

        $result = $this->getService(AttentionSummaryResolver::class)
            ->resolveForTeam($persona->team->id->toString());

        self::assertSame(1, $result->criticalAlertCount);
        self::assertSame(2, $result->unverifiedDomainCount);
        self::assertSame(1, $result->quarantineCount);
        self::assertSame(4, $result->totalCount);
        self::assertCount(3, $result->items);

        // Severity order: critical alerts → unverified domains → quarantine.
        self::assertSame('1 critical alert', $result->items[0]->label, 'singular form for count = 1');
        self::assertSame('text-error', $result->items[0]->colorClass);

        self::assertSame('2 unverified domains', $result->items[1]->label);
        self::assertSame('text-warning', $result->items[1]->colorClass);

        self::assertSame('1 report in quarantine', $result->items[2]->label);
        self::assertSame('text-warning', $result->items[2]->colorClass);
    }

    private function bootPersonaWithoutDomain(): \App\Tests\Fixtures\Persona
    {
        self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());

        return $fixtures->persona()->withoutDomain()->build();
    }

    private function persistAlert(EntityManagerInterface $em, Team $team, AlertSeverity $severity): void
    {
        $alert = new Alert(
            id: Uuid::uuid7(),
            team: $team,
            monitoredDomain: null,
            type: AlertType::FailureSpike,
            severity: $severity,
            title: 'Test alert',
            message: 'Test message',
            data: [],
            createdAt: new \DateTimeImmutable(),
            isRead: false,
            snoozedUntil: null,
        );
        $alert->popEvents();
        $em->persist($alert);
    }

    private function persistDomain(
        EntityManagerInterface $em,
        Team $team,
        ?\DateTimeImmutable $dmarcVerifiedAt,
    ): MonitoredDomain {
        $id = Uuid::uuid7();
        $domain = new MonitoredDomain(
            id: $id,
            team: $team,
            domain: 'd-'.$id->toString().'.example',
            createdAt: new \DateTimeImmutable(),
            dmarcVerifiedAt: $dmarcVerifiedAt,
        );
        $domain->popEvents();
        $em->persist($domain);

        return $domain;
    }

    private function persistQuarantined(EntityManagerInterface $em, string $domainName): void
    {
        $envelope = new ReceivedReportEmail(
            id: Uuid::uuid7(),
            source: ReportSource::CentralInbox,
            messageId: '<env-'.Uuid::uuid7()->toString().'@test>',
            fromAddress: 'noreply-dmarc@google.com',
            subject: 'Report Domain: '.$domainName,
            receivedAt: new \DateTimeImmutable('-2 hours'),
            ingestedAt: new \DateTimeImmutable('-2 hours'),
            sizeBytes: 100,
            rawEml: 'fake',
            mailboxConnection: null,
        );
        $em->persist($envelope);

        $xml = '<feedback></feedback>';
        $compressed = gzencode($xml);
        assert(false !== $compressed);

        $quarantine = new QuarantinedDmarcReport(
            id: Uuid::uuid7(),
            receivedEmail: $envelope,
            domainName: $domainName,
            externalReportId: 'ext-'.Uuid::uuid7()->toString(),
            reporterOrg: 'google.com',
            reporterEmail: 'noreply-dmarc-support@google.com',
            dateRangeBegin: new \DateTimeImmutable('@1700000000'),
            dateRangeEnd: new \DateTimeImmutable('@1700086400'),
            quarantinedAt: new \DateTimeImmutable('-1 hour'),
            expiresAt: new \DateTimeImmutable('+60 days'),
            reason: QuarantineReason::UnknownDomain,
            reportXmlGz: $compressed,
        );
        $em->persist($quarantine);
    }
}
