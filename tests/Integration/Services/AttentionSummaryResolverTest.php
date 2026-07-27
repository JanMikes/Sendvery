<?php

declare(strict_types=1);

namespace App\Tests\Integration\Services;

use App\Entity\Alert;
use App\Entity\DomainHealthSnapshot;
use App\Entity\MonitoredDomain;
use App\Entity\QuarantinedDmarcReport;
use App\Entity\ReceivedReportEmail;
use App\Entity\Team;
use App\Query\GetDomainOverview;
use App\Results\DomainOverviewResult;
use App\Services\AttentionSummaryResolver;
use App\Services\HealthSummaryResolver;
use App\Tests\Fixtures\Persona;
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
 * Drives {@see AttentionSummaryResolver} through every branch of the `/app`
 * hero chip row: no signals, each signal alone, and all of them together.
 *
 * Lives in Integration/ rather than Unit/ because the count sources the
 * resolver injects ({@see \App\Query\GetAlerts},
 * {@see \App\Query\GetQuarantineList}) are `final readonly` per the project
 * convention and therefore cannot be doubled by PHPUnit. Seeding a real DB row
 * per branch is fast enough (single-digit ms per case under DAMA transactions)
 * and exercises the actual SQL each query issues — which is the contract we care
 * about.
 */
final class AttentionSummaryResolverTest extends WebTestCase
{
    #[Test]
    public function noSignalsProducesEmptyItemsAndZeroTotal(): void
    {
        $persona = $this->bootPersonaWithoutDomain();

        $result = $this->getService(AttentionSummaryResolver::class)
            ->resolveForTeam($persona->team->id->toString(), $this->domainsFor($persona));

        self::assertSame(0, $result->criticalAlertCount);
        self::assertSame(0, $result->attentionDomainCount);
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
            ->resolveForTeam($persona->team->id->toString(), $this->domainsFor($persona));

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
            ->resolveForTeam($persona->team->id->toString(), $this->domainsFor($persona));

        self::assertSame(2, $result->unverifiedDomainCount);
        self::assertSame(2, $result->totalCount);
        self::assertCount(1, $result->items);
        self::assertSame('2 unverified domains', $result->items[0]->label);
        self::assertSame('dashboard_domains', $result->items[0]->route);
        self::assertSame(['status' => 'unverified'], $result->items[0]->routeParams);
        self::assertSame(
            'text-error',
            $result->items[0]->colorClass,
            'Unverified is the error tone on the domain cards and on the per-domain banner; the hero chip has to agree.',
        );
    }

    #[Test]
    public function onlyQuarantineProducesOneItem(): void
    {
        $persona = $this->bootPersonaWithoutDomain();
        $em = $this->getService(EntityManagerInterface::class);

        // Quarantine visibility requires a matching monitored_domain for the
        // team. It has to be a FULLY healthy domain, otherwise it would
        // legitimately contribute a domain chip of its own and this branch would
        // no longer be testing "quarantine alone".
        $domain = $this->persistHealthyDomain($em, $persona->team);
        $this->persistQuarantined($em, $domain->domain);
        $em->flush();

        $result = $this->getService(AttentionSummaryResolver::class)
            ->resolveForTeam($persona->team->id->toString(), $this->domainsFor($persona));

        self::assertSame(0, $result->attentionDomainCount);
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
    public function domainsNeedingAttentionGetTheirOwnChipLinkedToTheMatchingFilter(): void
    {
        $persona = $this->bootPersonaWithoutDomain();
        $em = $this->getService(EntityManagerInterface::class);

        // DMARC verified, but SPF/DKIM/MX unknown — the classifier's Attention
        // bucket, which this summary used to be blind to entirely.
        $this->persistDomain($em, $persona->team, dmarcVerifiedAt: new \DateTimeImmutable('-1 day'));
        $em->flush();

        $result = $this->getService(AttentionSummaryResolver::class)
            ->resolveForTeam($persona->team->id->toString(), $this->domainsFor($persona));

        self::assertSame(1, $result->attentionDomainCount);
        self::assertSame(0, $result->unverifiedDomainCount);
        self::assertSame(1, $result->totalCount);
        self::assertCount(1, $result->items);
        self::assertSame('1 needs attention', $result->items[0]->label);
        self::assertSame('dashboard_domains', $result->items[0]->route);
        self::assertSame(['status' => 'attention'], $result->items[0]->routeParams);
        self::assertSame('text-warning', $result->items[0]->colorClass);
    }

    #[Test]
    public function heroChipsCannotContradictTheHealthHeadlineTheySitUnder(): void
    {
        // The bug this pins down: the hero said "3 domains need attention" in the
        // headline and "1 thing needs your attention today: 1 unverified domain"
        // on the line right below, because the summary only knew about the
        // Unverified bucket. Both surfaces now classify the same domain rows, so
        // their domain counts have to match exactly.
        $persona = $this->bootPersonaWithoutDomain();
        $em = $this->getService(EntityManagerInterface::class);

        $this->persistHealthyDomain($em, $persona->team);
        $this->persistDomain($em, $persona->team, dmarcVerifiedAt: new \DateTimeImmutable('-1 day'));
        $this->persistDomain($em, $persona->team, dmarcVerifiedAt: new \DateTimeImmutable('-2 days'));
        $this->persistDomain($em, $persona->team, dmarcVerifiedAt: null);
        $em->flush();

        $domains = $this->domainsFor($persona);

        $attention = $this->getService(AttentionSummaryResolver::class)
            ->resolveForTeam($persona->team->id->toString(), $domains);
        $health = $this->getService(HealthSummaryResolver::class)
            ->resolve($domains, null, null);

        self::assertSame('2 domains need attention', $health->headline);
        self::assertSame(
            $health->domainsAttentionCount,
            $attention->attentionDomainCount,
            'The hero chips and the headline above them must count attention domains identically.',
        );
        self::assertSame(
            $health->domainsUnverifiedCount,
            $attention->unverifiedDomainCount,
            'The hero chips and the headline above them must count unverified domains identically.',
        );
    }

    #[Test]
    public function allSignalsProduceSeverityOrderedItems(): void
    {
        $persona = $this->bootPersonaWithoutDomain();
        $em = $this->getService(EntityManagerInterface::class);

        $this->persistAlert($em, $persona->team, AlertSeverity::Critical);
        $quarantineHost = $this->persistHealthyDomain($em, $persona->team);
        $this->persistDomain($em, $persona->team, dmarcVerifiedAt: new \DateTimeImmutable('-1 day'));
        $this->persistDomain($em, $persona->team, dmarcVerifiedAt: null);
        $this->persistDomain($em, $persona->team, dmarcVerifiedAt: null);
        $this->persistQuarantined($em, $quarantineHost->domain);
        $em->flush();

        $result = $this->getService(AttentionSummaryResolver::class)
            ->resolveForTeam($persona->team->id->toString(), $this->domainsFor($persona));

        self::assertSame(1, $result->criticalAlertCount);
        self::assertSame(1, $result->attentionDomainCount);
        self::assertSame(2, $result->unverifiedDomainCount);
        self::assertSame(1, $result->quarantineCount);
        self::assertSame(5, $result->totalCount);
        self::assertCount(4, $result->items);

        // Severity order: critical alerts → attention domains → unverified
        // domains → quarantine.
        self::assertSame('1 critical alert', $result->items[0]->label, 'singular form for count = 1');
        self::assertSame('text-error', $result->items[0]->colorClass);

        self::assertSame('1 needs attention', $result->items[1]->label);
        self::assertSame('text-warning', $result->items[1]->colorClass);

        self::assertSame('2 unverified domains', $result->items[2]->label);
        self::assertSame('text-error', $result->items[2]->colorClass);

        self::assertSame('1 report in quarantine', $result->items[3]->label);
        self::assertSame('text-warning', $result->items[3]->colorClass);
    }

    private function bootPersonaWithoutDomain(): Persona
    {
        self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());

        return $fixtures->persona()->withoutDomain()->build();
    }

    /**
     * @return array<DomainOverviewResult>
     */
    private function domainsFor(Persona $persona): array
    {
        return $this->getService(GetDomainOverview::class)
            ->forTeams([$persona->team->id->toString()]);
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

    /**
     * All four protocols in place and no reports yet — which the classifier calls
     * Healthy, because a correctly configured domain waiting for its first report
     * has nothing wrong with it.
     */
    private function persistHealthyDomain(EntityManagerInterface $em, Team $team): MonitoredDomain
    {
        $id = Uuid::uuid7();
        $domain = new MonitoredDomain(
            id: $id,
            team: $team,
            domain: 'healthy-'.$id->toString().'.example',
            createdAt: new \DateTimeImmutable(),
            spfVerifiedAt: new \DateTimeImmutable('-2 days'),
            dkimVerifiedAt: new \DateTimeImmutable('-2 days'),
            dmarcVerifiedAt: new \DateTimeImmutable('-2 days'),
        );
        $domain->popEvents();
        $em->persist($domain);

        $em->persist(new DomainHealthSnapshot(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            grade: 'A',
            score: 95,
            spfScore: 100,
            dkimScore: 100,
            dmarcScore: 100,
            mxScore: 95,
            blacklistScore: 100,
            checkedAt: new \DateTimeImmutable('-1 hour'),
        ));

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
