<?php

declare(strict_types=1);

namespace App\Tests\Integration\Query;

use App\Entity\DmarcRecord;
use App\Entity\DmarcReport;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Query\GetDomainReportData;
use App\Tests\IntegrationTestCase;
use App\Value\AuthResult;
use App\Value\Disposition;
use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Twig\Environment;

/**
 * The exported PDF is something a customer forwards to their boss, so a
 * fabricated "0.0% pass rate" on a domain that has simply not been reported on
 * yet is the most expensive place for the no-data lie to appear.
 */
final class GetDomainReportDataTest extends IntegrationTestCase
{
    #[Test]
    public function aDomainWithNoReportsHasNoPassRateRatherThanZero(): void
    {
        $query = $this->getService(GetDomainReportData::class);
        [$domainId, $teamId] = $this->seedDomain(withMessages: false);

        $data = $query->forDomain($domainId, [$teamId]);

        self::assertNotNull($data);
        self::assertSame(0, $data['total_messages']);
        self::assertNull(
            $data['pass_rate'],
            'A domain with no counted messages must report no pass rate, never 0%.',
        );
    }

    #[Test]
    public function aDomainWithTrafficReportsItsMeasuredPassRate(): void
    {
        $query = $this->getService(GetDomainReportData::class);
        [$domainId, $teamId] = $this->seedDomain(withMessages: true);

        $data = $query->forDomain($domainId, [$teamId]);

        self::assertNotNull($data);
        self::assertSame(100.0, $data['pass_rate']);
    }

    #[Test]
    public function theExportedReportSaysNoReportsReceivedInsteadOfPrintingZeroPercent(): void
    {
        $twig = $this->getService(Environment::class);

        $html = $twig->render('pdf/domain_report.html.twig', [
            'domain' => (object) ['domainName' => 'brand-new.example'],
            'generatedAt' => new \DateTimeImmutable('2026-07-01 12:00:00'),
            'healthSnapshot' => null,
            'senders' => [],
            'reportData' => [
                'domain_name' => 'brand-new.example',
                'total_reports' => 0,
                'total_messages' => 0,
                'pass_rate' => null,
                'authorized_senders' => 0,
                'total_senders' => 0,
                'blacklisted_ips' => 0,
                'latest_grade' => null,
                'latest_score' => null,
            ],
        ]);

        self::assertStringContainsString('No reports received yet', $html);
        self::assertStringNotContainsString('0.0%', $html);
    }

    #[Test]
    public function forDomainWithoutAnyReadableTeamReturnsNothing(): void
    {
        $query = $this->getService(GetDomainReportData::class);

        self::assertNull($query->forDomain(Uuid::uuid7()->toString(), []));
    }

    /** @return array{0: string, 1: string} domain id, team id */
    private function seedDomain(bool $withMessages): array
    {
        $em = $this->getService(EntityManagerInterface::class);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'PDF Export Test',
            slug: 'pdf-export-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $team->popEvents();
        $em->persist($team);

        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'pdf-export.example',
            createdAt: new \DateTimeImmutable(),
        );
        $domain->popEvents();
        $em->persist($domain);

        if ($withMessages) {
            $report = new DmarcReport(
                id: Uuid::uuid7(),
                monitoredDomain: $domain,
                reporterOrg: 'google.com',
                reporterEmail: 'noreply@google.com',
                externalReportId: 'ext-'.Uuid::uuid7()->toString(),
                dateRangeBegin: new \DateTimeImmutable('-2 days'),
                dateRangeEnd: new \DateTimeImmutable('-1 day'),
                policyDomain: $domain->domain,
                policyAdkim: DmarcAlignment::Relaxed,
                policyAspf: DmarcAlignment::Relaxed,
                policyP: DmarcPolicy::None,
                policySp: null,
                policyPct: 100,
                rawXml: '<feedback></feedback>',
                processedAt: new \DateTimeImmutable(),
            );
            $em->persist($report);
            $em->persist(new DmarcRecord(
                id: Uuid::uuid7(),
                dmarcReport: $report,
                sourceIp: '1.2.3.4',
                count: 20,
                disposition: Disposition::None,
                dkimResult: AuthResult::Pass,
                spfResult: AuthResult::Pass,
                headerFrom: $domain->domain,
            ));
        }

        $em->flush();

        return [$domain->id->toString(), $team->id->toString()];
    }
}
