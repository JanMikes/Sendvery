<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\Alert;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Events\DnsCheckCompleted;
use App\MessageHandler\ResolveDnsAlertsWhenRecordFixed;
use App\Tests\IntegrationTestCase;
use App\Value\AlertType;
use App\Value\DnsCheckType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;

/**
 * A "MX is broken for example.com" alert stops describing reality the moment
 * the record validates again. Once fixed it must stop demanding attention
 * without the user having to acknowledge a problem that no longer exists.
 */
final class ResolveDnsAlertsWhenRecordFixedTest extends IntegrationTestCase
{
    /** @return array{Team, MonitoredDomain} */
    private function createTeamAndDomain(): array
    {
        $em = $this->getService(EntityManagerInterface::class);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Resolve Test',
            slug: 'resolve-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $team->popEvents();
        $em->persist($team);

        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'resolve-'.Uuid::uuid7()->toString().'.example',
            createdAt: new \DateTimeImmutable(),
        );
        $domain->popEvents();
        $em->persist($domain);
        $em->flush();

        return [$team, $domain];
    }

    /** @param array<string, mixed> $data */
    private function persistAlert(
        Team $team,
        ?MonitoredDomain $domain,
        AlertType $type,
        string $title,
        array $data,
        ?\DateTimeImmutable $resolvedAt = null,
    ): Alert {
        $em = $this->getService(EntityManagerInterface::class);

        $alert = new Alert(
            id: Uuid::uuid7(),
            team: $team,
            monitoredDomain: $domain,
            type: $type,
            severity: $type->defaultSeverity(),
            title: $title,
            message: 'msg',
            data: $data,
            createdAt: new \DateTimeImmutable('2026-03-25 10:00:00'),
            resolvedAt: $resolvedAt,
        );
        $alert->popEvents();
        $em->persist($alert);
        $em->flush();

        return $alert;
    }

    private function dispatchCheck(
        Team $team,
        MonitoredDomain $domain,
        DnsCheckType $type,
        bool $isValid,
    ): void {
        $em = $this->getService(EntityManagerInterface::class);

        $this->getService(ResolveDnsAlertsWhenRecordFixed::class)(new DnsCheckCompleted(
            dnsCheckResultId: Uuid::uuid7(),
            domainId: $domain->id,
            teamId: $team->id,
            type: $type,
            hasChanged: true,
            isValid: $isValid,
            rawRecord: $isValid ? 'a valid record' : null,
            previousRawRecord: 'something',
        ));
        $em->flush();
    }

    #[Test]
    public function aNowValidRecordResolvesTheBrokenRecordAlertItRaised(): void
    {
        [$team, $domain] = $this->createTeamAndDomain();

        $alert = $this->persistAlert(
            $team,
            $domain,
            AlertType::DnsRecordInvalid,
            'MX is broken for '.$domain->domain,
            ['dns_check_type' => 'mx', 'first_check' => true],
        );

        $this->dispatchCheck($team, $domain, DnsCheckType::Mx, isValid: true);

        self::assertTrue($alert->isResolved(), 'A record that validates again must resolve the "is broken" alert.');
        self::assertNotNull($alert->resolvedAt);
        self::assertGreaterThanOrEqual(
            $alert->createdAt,
            $alert->resolvedAt,
            'The resolution is stamped when the fix was observed, never before the alert existed.',
        );
    }

    #[Test]
    public function aNowValidRecordResolvesTheRecordRemovedAlertItRaised(): void
    {
        [$team, $domain] = $this->createTeamAndDomain();

        $alert = $this->persistAlert(
            $team,
            $domain,
            AlertType::DnsRecordMissing,
            'SPF record removed for '.$domain->domain,
            ['dns_check_type' => 'spf'],
        );

        $this->dispatchCheck($team, $domain, DnsCheckType::Spf, isValid: true);

        self::assertTrue($alert->isResolved());
    }

    #[Test]
    public function aStillFailingRecordLeavesItsAlertsDemandingAttention(): void
    {
        [$team, $domain] = $this->createTeamAndDomain();

        $alert = $this->persistAlert(
            $team,
            $domain,
            AlertType::DnsRecordInvalid,
            'DKIM is broken for '.$domain->domain,
            ['dns_check_type' => 'dkim'],
        );

        $this->dispatchCheck($team, $domain, DnsCheckType::Dkim, isValid: false);

        self::assertFalse($alert->isResolved(), 'An unfixed record must keep its alert open.');
    }

    #[Test]
    public function fixingOneRecordTypeLeavesTheOtherTypesAlertsOpen(): void
    {
        [$team, $domain] = $this->createTeamAndDomain();

        $spfAlert = $this->persistAlert(
            $team,
            $domain,
            AlertType::DnsRecordInvalid,
            'SPF is broken for '.$domain->domain,
            ['dns_check_type' => 'spf'],
        );
        $dkimAlert = $this->persistAlert(
            $team,
            $domain,
            AlertType::DnsRecordInvalid,
            'DKIM is broken for '.$domain->domain,
            ['dns_check_type' => 'dkim'],
        );

        $this->dispatchCheck($team, $domain, DnsCheckType::Spf, isValid: true);

        self::assertTrue($spfAlert->isResolved());
        self::assertFalse($dkimAlert->isResolved(), 'A healthy SPF record says nothing about DKIM.');
    }

    #[Test]
    public function informationalTransitionAlertsAreNeverResolved(): void
    {
        // "record changed" / "record published" describe a moment in time, not
        // a problem — there is nothing to resolve, and leaving them alone also
        // keeps AlertOnDnsChange free to raise a fresh informational alert for
        // the very same recovering check.
        [$team, $domain] = $this->createTeamAndDomain();

        $changed = $this->persistAlert(
            $team,
            $domain,
            AlertType::DnsRecordChanged,
            'SPF record changed for '.$domain->domain,
            ['dns_check_type' => 'spf'],
        );
        $published = $this->persistAlert(
            $team,
            $domain,
            AlertType::DnsRecordPublished,
            'SPF record published for '.$domain->domain,
            ['dns_check_type' => 'spf'],
        );

        $this->dispatchCheck($team, $domain, DnsCheckType::Spf, isValid: true);

        self::assertFalse($changed->isResolved());
        self::assertFalse($published->isResolved());
    }

    #[Test]
    public function anotherDomainsAlertsAreLeftAlone(): void
    {
        [$team, $domain] = $this->createTeamAndDomain();
        [$otherTeam, $otherDomain] = $this->createTeamAndDomain();

        $ours = $this->persistAlert(
            $team,
            $domain,
            AlertType::DnsRecordInvalid,
            'SPF is broken for '.$domain->domain,
            ['dns_check_type' => 'spf'],
        );
        $theirs = $this->persistAlert(
            $otherTeam,
            $otherDomain,
            AlertType::DnsRecordInvalid,
            'SPF is broken for '.$otherDomain->domain,
            ['dns_check_type' => 'spf'],
        );

        $this->dispatchCheck($team, $domain, DnsCheckType::Spf, isValid: true);

        self::assertTrue($ours->isResolved());
        self::assertFalse($theirs->isResolved(), 'Resolution must never leak across domains or tenants.');
    }

    #[Test]
    public function anAlreadyResolvedAlertKeepsItsOriginalResolutionTime(): void
    {
        [$team, $domain] = $this->createTeamAndDomain();
        $original = new \DateTimeImmutable('2026-03-27 04:00:00');

        $alert = $this->persistAlert(
            $team,
            $domain,
            AlertType::DnsRecordInvalid,
            'MX is broken for '.$domain->domain,
            ['dns_check_type' => 'mx'],
            resolvedAt: $original,
        );

        $this->dispatchCheck($team, $domain, DnsCheckType::Mx, isValid: true);

        self::assertSame(
            '2026-03-27 04:00:00',
            $alert->resolvedAt?->format('Y-m-d H:i:s'),
            'The nightly re-check must not keep bumping an old resolution timestamp.',
        );
    }

    #[Test]
    public function anAlertWithoutADnsCheckTypeIsNeverTouched(): void
    {
        // Non-DNS alerts (failure spikes, blacklist hits) carry no
        // dns_check_type, so a healthy DNS record must not silently close them.
        [$team, $domain] = $this->createTeamAndDomain();

        $alert = $this->persistAlert(
            $team,
            $domain,
            AlertType::FailureSpike,
            'Failure spike on '.$domain->domain,
            [],
        );

        $this->dispatchCheck($team, $domain, DnsCheckType::Spf, isValid: true);

        self::assertFalse($alert->isResolved());
    }
}
