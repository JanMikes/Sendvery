<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\DmarcRecord;
use App\Entity\DmarcReport;
use App\Entity\DnsCheckResult;
use App\Entity\DomainHealthSnapshot;
use App\Entity\MonitoredDomain;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use App\Value\AuthResult;
use App\Value\Disposition;
use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;
use App\Value\DnsCheckType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The "Fully healthy" and "Need attention" stat cards on `/app/domains` are
 * hyperlinks to `?status=healthy` and `?status=attention`. A number that is a
 * link to a list is a promise about that list.
 *
 * THE DEFECT THIS EXISTS FOR: the page derived those two counts from a THIRD
 * rule — `DnsHealthOverviewResult::hasSnapshot()` plus
 * `DomainHealthClassifier::isFullyHealthy()`, which asks only "are all four
 * protocols configured?" with no DMARC-verified precedence and no pass-rate arm
 * — while the card badge beside each domain and the list behind the link both
 * come from `classifyOverview()`. Three rules, one page. A domain with a 30%
 * pass rate counted as "Fully healthy" and appeared in the "Need attention"
 * list; a domain with a valid DMARC record that does not point at us counted as
 * "Fully healthy" and appeared in no list at all.
 *
 * The assertion is deliberately the invariant rather than fixed numbers: the
 * count a user reads must equal the number of domains they get when they click
 * it, whatever the fixture.
 */
final class DomainStatCountsMatchTheirOwnLinksTest extends WebTestCase
{
    #[Test]
    public function theFullyHealthyCountEqualsTheNumberOfDomainsBehindItsLink(): void
    {
        $client = $this->clientWithDivergentDomains();

        $client->request('GET', '/app/domains');
        self::assertResponseIsSuccessful();
        $stat = $this->statCardValue($client, 'Fully healthy');

        $client->request('GET', '/app/domains?status=healthy');
        self::assertResponseIsSuccessful();

        self::assertSame(
            $this->renderedDomainCount($client),
            $stat,
            'The "Fully healthy" number is a link to the healthy list — the two must be the same set.',
        );
    }

    #[Test]
    public function theNeedAttentionCountEqualsTheNumberOfDomainsBehindItsLink(): void
    {
        $client = $this->clientWithDivergentDomains();

        $client->request('GET', '/app/domains');
        self::assertResponseIsSuccessful();
        $stat = $this->statCardValue($client, 'Need attention');

        $client->request('GET', '/app/domains?status=attention');
        self::assertResponseIsSuccessful();

        self::assertSame(
            $this->renderedDomainCount($client),
            $stat,
            'A user told "0 need attention" must not find a domain waiting when they click through.',
        );
    }

    #[Test]
    public function theAwaitingFirstCheckCountEqualsTheNumberOfDomainsBehindItsLink(): void
    {
        // The snapshot axis is genuinely separate from the health verdict, and
        // it must stay consistent with its own chip while the other two change.
        $client = $this->clientWithDivergentDomains();

        $client->request('GET', '/app/domains');
        $stat = $this->statCardValue($client, 'Awaiting first check');

        $client->request('GET', '/app/domains?status=unchecked');

        self::assertSame($this->renderedDomainCount($client), $stat);
    }

    #[Test]
    public function aDomainWhoseMailIsMostlyFailingIsCountedAsNeedingAttentionNotAsFullyHealthy(): void
    {
        // The named case. Four configured protocols is not a clean bill of
        // health when 70% of the domain's mail fails authentication — and the
        // badge on that very card already says so.
        $client = $this->clientWithDivergentDomains();

        $client->request('GET', '/app/domains');

        self::assertGreaterThan(
            0,
            $this->statCardValue($client, 'Need attention'),
            'A domain the page paints amber must be counted by the amber stat.',
        );
    }

    private function statCardValue(KernelBrowser $client, string $title): int
    {
        $card = $client->getCrawler()
            ->filter(sprintf('h3:contains("%s")', $title))
            ->ancestors()
            ->filter('div.card')
            ->first();
        self::assertCount(1, $card, sprintf('The "%s" stat card must render on /app/domains', $title));

        self::assertSame(1, preg_match('/(\d+)/', $card->text(), $matches), sprintf('The "%s" card must print a number', $title));

        return (int) $matches[1];
    }

    /**
     * Each domain card carries one stretched anchor whose accessible name opens
     * that domain — a semantic handle rather than a layout class.
     */
    private function renderedDomainCount(KernelBrowser $client): int
    {
        return $client->getCrawler()->filter('a[aria-label^="Open "]')->count();
    }

    /**
     * Three domains that the two rules disagree about, plus one the snapshot
     * axis owns.
     */
    private function clientWithDivergentDomains(): KernelBrowser
    {
        $client = self::createClient();
        $em = $this->getService(EntityManagerInterface::class);
        $fixtures = TestFixtures::fromContainer(self::getContainer());

        $suffix = substr(uniqid('', true), -6);
        $persona = $fixtures->persona()
            ->emailPrefix('stat-parity-'.$suffix)
            ->teamName('Stat Parity '.$suffix)
            ->withDomain('unverified-but-configured-'.$suffix.'.example')
            ->build();
        assert(null !== $persona->domain);

        // (1) A valid DMARC record that does not route reports to us: every
        // protocol check passes, but `dmarc_verified_at` is null. Four-protocols
        // -only called this "Fully healthy"; the classifier calls it Unverified,
        // and the healthy list — which requires a verified DMARC record — has
        // never contained it.
        $this->configureFully($em, $persona->domain);
        $this->seedSnapshot($em, $persona->domain);
        $this->seedReport($em, $persona->domain, pass: 99, fail: 1);

        // (2) Verified, fully configured, and 70% of its mail fails. Four-
        // protocols-only has no pass-rate arm, so it counted here as healthy
        // while the card badge and the attention list both said otherwise.
        $lowPass = $fixtures->addExtraDomain($persona->team, 'low-pass-'.$suffix);
        $lowPass->dmarcVerifiedAt = new \DateTimeImmutable('-7 days');
        $this->configureFully($em, $lowPass);
        $this->seedSnapshot($em, $lowPass);
        $this->seedReport($em, $lowPass, pass: 3, fail: 7);

        // (3) The genuinely healthy one — the control.
        $healthy = $fixtures->addExtraDomain($persona->team, 'genuinely-healthy-'.$suffix);
        $healthy->dmarcVerifiedAt = new \DateTimeImmutable('-7 days');
        $this->configureFully($em, $healthy);
        $this->seedSnapshot($em, $healthy);
        $this->seedReport($em, $healthy, pass: 99, fail: 1);

        // (4) No nightly snapshot yet — the "Awaiting first check" axis.
        $unchecked = $fixtures->addExtraDomain($persona->team, 'never-swept-'.$suffix);
        $unchecked->dmarcVerifiedAt = new \DateTimeImmutable('-7 days');
        $this->configureFully($em, $unchecked);
        $this->seedReport($em, $unchecked, pass: 99, fail: 1);

        $em->flush();
        $client->loginUser($persona->user);

        return $client;
    }

    private function configureFully(EntityManagerInterface $em, MonitoredDomain $domain): void
    {
        foreach ([DnsCheckType::Spf, DnsCheckType::Dkim, DnsCheckType::Dmarc, DnsCheckType::Mx] as $type) {
            $check = new DnsCheckResult(
                id: Uuid::uuid7(),
                monitoredDomain: $domain,
                type: $type,
                checkedAt: new \DateTimeImmutable('-1 hour'),
                rawRecord: 'record',
                isValid: true,
                issues: [],
                details: [],
                previousRawRecord: null,
                hasChanged: false,
                isFirstCheck: false,
            );
            $check->popEvents();
            $em->persist($check);
        }
    }

    private function seedSnapshot(EntityManagerInterface $em, MonitoredDomain $domain): void
    {
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
    }

    private function seedReport(EntityManagerInterface $em, MonitoredDomain $domain, int $pass, int $fail): void
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
