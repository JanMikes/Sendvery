<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\MonitoredDomain;
use App\Entity\QuarantinedDmarcReport;
use App\Entity\ReceivedReportEmail;
use App\Entity\Team;
use App\Entity\TeamMembership;
use App\Entity\User;
use App\Tests\WebTestCase;
use App\Value\Reports\QuarantineReason;
use App\Value\Reports\ReportSource;
use App\Value\TeamRole;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Integration coverage for TASK-071: every quarantined-report row carries
 * a leading severity glyph + `border-l-{tone}` matching
 * `QuarantineReason::severityTone()`. The leading icon doubles as a "filter
 * by this reason" anchor.
 */
final class QuarantineListSeverityGlyphTest extends WebTestCase
{
    /**
     * @return array{
     *     client: KernelBrowser,
     *     team: Team,
     *     unknownDomain: string,
     *     unverifiedDomain: string,
     *     planOverageDomain: string,
     * }
     */
    private function bootClientWithThreeReasons(): array
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $userId = Uuid::uuid7();
        $user = new User(
            id: $userId,
            email: 'q-glyph-'.$userId->toString().'@example.com',
            createdAt: new \DateTimeImmutable(),
            onboardingCompletedAt: new \DateTimeImmutable(),
        );
        $user->popEvents();
        $em->persist($user);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Quarantine Glyph',
            slug: 'q-glyph-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $team->popEvents();
        $em->persist($team);

        $em->persist(new TeamMembership(
            id: Uuid::uuid7(),
            user: $user,
            team: $team,
            role: TeamRole::Owner,
            joinedAt: new \DateTimeImmutable(),
        ));

        $unknownDomainName = 'unknown-'.substr(Uuid::uuid7()->toString(), 0, 8).'.test';
        $unverifiedDomainName = 'unverified-'.substr(Uuid::uuid7()->toString(), 0, 8).'.test';
        $planOverageDomainName = 'overage-'.substr(Uuid::uuid7()->toString(), 0, 8).'.test';

        foreach ([$unknownDomainName, $unverifiedDomainName, $planOverageDomainName] as $name) {
            $domain = new MonitoredDomain(
                id: Uuid::uuid7(),
                team: $team,
                domain: $name,
                createdAt: new \DateTimeImmutable(),
            );
            $domain->popEvents();
            $em->persist($domain);
        }

        $this->persistQuarantine($em, $unknownDomainName, QuarantineReason::UnknownDomain);
        $this->persistQuarantine($em, $unverifiedDomainName, QuarantineReason::UnverifiedDomain);
        $this->persistQuarantine($em, $planOverageDomainName, QuarantineReason::PlanOverage);

        $em->flush();

        $client->loginUser($user);

        return [
            'client' => $client,
            'team' => $team,
            'unknownDomain' => $unknownDomainName,
            'unverifiedDomain' => $unverifiedDomainName,
            'planOverageDomain' => $planOverageDomainName,
        ];
    }

    private function persistQuarantine(
        EntityManagerInterface $em,
        string $domainName,
        QuarantineReason $reason,
    ): QuarantinedDmarcReport {
        $envelope = new ReceivedReportEmail(
            id: Uuid::uuid7(),
            source: ReportSource::CentralInbox,
            messageId: '<env-'.Uuid::uuid7()->toString().'@test>',
            fromAddress: 'noreply@google.com',
            subject: 'Glyph fixture',
            receivedAt: new \DateTimeImmutable(),
            ingestedAt: new \DateTimeImmutable(),
            sizeBytes: 1024,
            rawEml: 'x',
        );
        $em->persist($envelope);

        $compressed = gzencode('<feedback/>');
        assert(false !== $compressed);

        $quarantine = new QuarantinedDmarcReport(
            id: Uuid::uuid7(),
            receivedEmail: $envelope,
            domainName: $domainName,
            externalReportId: 'ext-'.Uuid::uuid7()->toString(),
            reporterOrg: 'google.com',
            reporterEmail: 'noreply@google.com',
            dateRangeBegin: new \DateTimeImmutable('@1700000000'),
            dateRangeEnd: new \DateTimeImmutable('@1700086400'),
            quarantinedAt: new \DateTimeImmutable('-1 hour'),
            expiresAt: new \DateTimeImmutable('+60 days'),
            reason: $reason,
            reportXmlGz: $compressed,
        );
        $em->persist($quarantine);

        return $quarantine;
    }

    #[Test]
    public function planOverageRowCarriesErrorBorderAndCircleGlyph(): void
    {
        $data = $this->bootClientWithThreeReasons();

        $data['client']->request('GET', '/app/quarantine?reason=plan_overage');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString($data['planOverageDomain'], $body);
        self::assertStringContainsString('border-l-error', $body);
        // Exclamation-circle path from the shared severity macro.
        self::assertStringContainsString('M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z', $body);
    }

    #[Test]
    public function unverifiedDomainRowCarriesWarningBorderAndTriangleGlyph(): void
    {
        $data = $this->bootClientWithThreeReasons();

        $data['client']->request('GET', '/app/quarantine?reason=unverified_domain');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString($data['unverifiedDomain'], $body);
        self::assertStringContainsString('border-l-warning', $body);
        // Exclamation-triangle path.
        self::assertStringContainsString('M12 9v2m0 4h.01m-6.938 4h13.856', $body);
    }

    #[Test]
    public function unknownDomainRowCarriesInfoBorderAndInfoCircleGlyph(): void
    {
        $data = $this->bootClientWithThreeReasons();

        $data['client']->request('GET', '/app/quarantine?reason=unknown_domain');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString($data['unknownDomain'], $body);
        self::assertStringContainsString('border-l-info', $body);
        // Info-circle path (the variant with `13 16h-1v-4` prefix).
        self::assertStringContainsString('M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z', $body);
    }

    #[Test]
    public function leadingIconAnchorLinksToTheMatchingReasonFilter(): void
    {
        $data = $this->bootClientWithThreeReasons();

        $data['client']->request('GET', '/app/quarantine');

        self::assertResponseIsSuccessful();
        $crawler = $data['client']->getCrawler();

        // Each row's leading-icon column is an anchor (`relative z-20`) whose
        // href applies the same-reason filter. Three rows → three leading-icon
        // anchors. The crawler scopes the lookup to the body of the table so
        // the navigation chips above aren't double-counted.
        $leadingAnchors = $crawler->filter('table tbody tr td.w-8 a');
        self::assertCount(3, $leadingAnchors, 'Each quarantine row should expose its reason via the leading-icon anchor.');

        /** @var list<string> $hrefs */
        $hrefs = $leadingAnchors->extract(['href']);
        sort($hrefs);
        self::assertSame([
            '/app/quarantine?reason=plan_overage',
            '/app/quarantine?reason=unknown_domain',
            '/app/quarantine?reason=unverified_domain',
        ], $hrefs);

        // The leading-icon anchors must carry `relative z-20` so they win
        // over the stretched-row anchor (TASK-018 stacking-order contract).
        $zClasses = $crawler->filter('table tbody tr td.w-8 a.relative.z-20');
        self::assertCount(3, $zClasses);
    }

    #[Test]
    public function everyRowCarriesExactlyOneSeverityBorderTone(): void
    {
        $data = $this->bootClientWithThreeReasons();

        $data['client']->request('GET', '/app/quarantine');

        self::assertResponseIsSuccessful();
        $crawler = $data['client']->getCrawler();
        $rows = $crawler->filter('table tbody tr');
        self::assertCount(3, $rows);

        foreach ($rows as $tr) {
            assert($tr instanceof \DOMElement);
            $class = $tr->getAttribute('class');
            $matches = preg_match_all('/border-l-(error|warning|info)/', $class);
            self::assertSame(
                1,
                $matches,
                'Each quarantine row must carry exactly one severity border tone; got: '.$class,
            );
        }
    }

    #[Test]
    public function reasonBadgeStaysPresentAsPreciseTextualLabel(): void
    {
        // Acceptance guard: the leading glyph is the scannable cue, the
        // existing reason badge is the precise label — removing the badge
        // would regress the dual-signal contract.
        $data = $this->bootClientWithThreeReasons();

        $data['client']->request('GET', '/app/quarantine');

        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString('>Unknown domain</a>', $body);
        self::assertStringContainsString('>Unverified domain</a>', $body);
        self::assertStringContainsString('>Plan overage</a>', $body);
    }
}
