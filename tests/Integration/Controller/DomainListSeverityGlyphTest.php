<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\DmarcRecord;
use App\Entity\DmarcReport;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use App\Value\AuthResult;
use App\Value\Disposition;
use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Integration coverage for TASK-066: every DomainCard rendered on /app/domains
 * carries a leading severity glyph + matching left-border tone. Three seeded
 * domains exercise the three branches of DomainHealthFilter::fromOverview().
 */
final class DomainListSeverityGlyphTest extends WebTestCase
{
    /**
     * @return array{client: KernelBrowser, healthyDomain: string, attentionDomain: string, unverifiedDomain: string}
     */
    private function createClientWithThreeDomains(): array
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $fixtures = TestFixtures::fromContainer(self::getContainer());

        $suffix = substr(uniqid('', true), -6);

        $persona = $fixtures->persona()
            ->emailPrefix('glyph-'.$suffix)
            ->teamName('Glyph Test '.$suffix)
            ->withDomain('unverified-'.$suffix.'.example')
            ->build();
        assert(null !== $persona->domain);
        $unverifiedDomain = $persona->domain;

        $healthyDomain = $fixtures->addExtraDomain($persona->team, 'healthy-'.$suffix);
        $healthyDomain->dmarcVerifiedAt = new \DateTimeImmutable('-7 days');

        $attentionDomain = $fixtures->addExtraDomain($persona->team, 'attention-'.$suffix);
        $attentionDomain->dmarcVerifiedAt = new \DateTimeImmutable('-7 days');

        $this->persistReport($em, $healthyDomain, pass: 10, fail: 0);
        $this->persistReport($em, $attentionDomain, pass: 3, fail: 7);

        $em->flush();
        $client->loginUser($persona->user);

        return [
            'client' => $client,
            'healthyDomain' => $healthyDomain->domain,
            'attentionDomain' => $attentionDomain->domain,
            'unverifiedDomain' => $unverifiedDomain->domain,
        ];
    }

    private function persistReport(EntityManagerInterface $em, \App\Entity\MonitoredDomain $domain, int $pass, int $fail): void
    {
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

        if ($pass > 0) {
            $em->persist(new DmarcRecord(
                id: Uuid::uuid7(),
                dmarcReport: $report,
                sourceIp: '1.2.3.4',
                count: $pass,
                disposition: Disposition::None,
                dkimResult: AuthResult::Pass,
                spfResult: AuthResult::Pass,
                headerFrom: $domain->domain,
            ));
        }

        if ($fail > 0) {
            $em->persist(new DmarcRecord(
                id: Uuid::uuid7(),
                dmarcReport: $report,
                sourceIp: '5.6.7.8',
                count: $fail,
                disposition: Disposition::None,
                dkimResult: AuthResult::Fail,
                spfResult: AuthResult::Fail,
                headerFrom: $domain->domain,
            ));
        }
    }

    #[Test]
    public function eachRenderedCardCarriesExactlyOneSeverityGlyph(): void
    {
        $data = $this->createClientWithThreeDomains();

        $data['client']->request('GET', '/app/domains');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();

        // Three domains → three glyph chips. The chip's `w-10 h-10 rounded-full`
        // wrapper is unique to the severity icon block on DomainCard.
        $glyphCount = substr_count($body, 'w-10 h-10 rounded-full');
        self::assertSame(3, $glyphCount, 'Expected exactly one severity glyph per rendered card.');
    }

    #[Test]
    public function healthyDomainRendersSuccessToneClassesAndCheckPath(): void
    {
        $data = $this->createClientWithThreeDomains();

        $data['client']->request('GET', '/app/domains?status=healthy');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString($data['healthyDomain'], $body);
        self::assertStringContainsString('border-l-success', $body);
        self::assertStringContainsString('bg-success/10', $body);
        self::assertStringContainsString('text-success', $body);
        // Canonical check-circle path lifted from overview.html.twig:20.
        self::assertStringContainsString('M9 12l2 2 4-4m5.618-4.016', $body);
    }

    #[Test]
    public function attentionDomainRendersWarningToneClassesAndTrianglePath(): void
    {
        $data = $this->createClientWithThreeDomains();

        $data['client']->request('GET', '/app/domains?status=attention');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString($data['attentionDomain'], $body);
        self::assertStringContainsString('border-l-warning', $body);
        self::assertStringContainsString('bg-warning/10', $body);
        self::assertStringContainsString('text-warning', $body);
        // Exclamation-triangle path from alerts.html.twig:101.
        self::assertStringContainsString('M12 9v2m0 4h.01m-6.938 4h13.856', $body);
    }

    #[Test]
    public function unverifiedDomainRendersErrorToneClassesAndCirclePath(): void
    {
        $data = $this->createClientWithThreeDomains();

        $data['client']->request('GET', '/app/domains?status=unverified');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString($data['unverifiedDomain'], $body);
        self::assertStringContainsString('border-l-error', $body);
        self::assertStringContainsString('bg-error/10', $body);
        self::assertStringContainsString('text-error', $body);
        // Exclamation-circle path (canonical across the codebase).
        self::assertStringContainsString('M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z', $body);
    }

    #[Test]
    public function filterChipsStillRenderAfterAddingSeverityGlyph(): void
    {
        $data = $this->createClientWithThreeDomains();

        $data['client']->request('GET', '/app/domains');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString('href="/app/domains?status=healthy"', $body);
        self::assertStringContainsString('href="/app/domains?status=attention"', $body);
        self::assertStringContainsString('href="/app/domains?status=unverified"', $body);
    }
}
