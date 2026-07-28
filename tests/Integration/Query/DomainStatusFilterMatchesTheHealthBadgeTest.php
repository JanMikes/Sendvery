<?php

declare(strict_types=1);

namespace App\Tests\Integration\Query;

use App\Entity\DmarcRecord;
use App\Entity\DmarcReport;
use App\Entity\DnsCheckResult;
use App\Entity\DomainHealthSnapshot;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Query\GetDomainOverview;
use App\Services\DomainHealthClassifier;
use App\Tests\IntegrationTestCase;
use App\Value\AuthResult;
use App\Value\Disposition;
use App\Value\DmarcAlignment;
use App\Value\DmarcPolicy;
use App\Value\DnsCheckType;
use App\Value\DomainHealthFilter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;

/**
 * A status chip and the badge on the cards behind it must never disagree.
 *
 * THE DEFECT THIS EXISTS FOR: the `?status=` SQL filter judged a domain on two
 * inputs (DMARC verified + 30-day pass rate) while `DomainHealthClassifier` —
 * the thing that paints the card and feeds the "Need attention" counter — judges
 * it on six (those two plus all four per-protocol DNS verdicts). A domain with a
 * broken SPF record therefore showed the amber badge, counted in the headline
 * stat, and then vanished when the user clicked the chip to see it. The file
 * itself called the gap "a v2 refinement".
 *
 * This is written as a parity assertion rather than a list of expected domain
 * names on purpose: it compares the SQL filter's output against the classifier's
 * verdict over the same rows, so any future divergence fails here regardless of
 * which side moved.
 */
final class DomainStatusFilterMatchesTheHealthBadgeTest extends IntegrationTestCase
{
    /** @return iterable<string, array{DomainHealthFilter}> */
    public static function statusCases(): iterable
    {
        yield 'healthy chip' => [DomainHealthFilter::Healthy];
        yield 'needs-attention chip' => [DomainHealthFilter::Attention];
        yield 'unverified chip' => [DomainHealthFilter::Unverified];
    }

    #[Test]
    #[DataProvider('statusCases')]
    public function theChipListsExactlyTheDomainsWhoseBadgeCarriesThatStatus(DomainHealthFilter $status): void
    {
        $teamId = $this->seedEveryHealthShape();
        $query = $this->getService(GetDomainOverview::class);
        $classifier = $this->getService(DomainHealthClassifier::class);

        $expected = [];
        foreach ($query->forTeams([$teamId]) as $row) {
            if ($status === $classifier->classifyOverview($row)) {
                $expected[] = $row->domainName;
            }
        }
        sort($expected);

        $listed = array_map(static fn ($row): string => $row->domainName, $query->forTeams([$teamId], $status));
        sort($listed);

        self::assertNotSame([], $expected, 'The fixture must exercise this status, otherwise the parity assertion is vacuous.');
        self::assertSame(
            $expected,
            $listed,
            'Clicking a status chip must list exactly the domains whose card carries that status — no more, and none missing.',
        );
    }

    #[Test]
    public function aDomainWithABrokenRecordIsListedByTheChipThatCountsIt(): void
    {
        // The user-visible shape of the defect, named explicitly: everything about
        // this domain looks excellent except one record that is broken right now,
        // so it earns the amber badge and the "Need attention" tally — and used to
        // be absent from the list that tally links to.
        $teamId = $this->seedEveryHealthShape();
        $query = $this->getService(GetDomainOverview::class);

        $listed = array_map(
            static fn ($row): string => $row->domainName,
            $query->forTeams([$teamId], DomainHealthFilter::Attention),
        );

        self::assertContains('broken-spf.example', $listed);
        self::assertContains('broken-mx.example', $listed);
        self::assertNotContains('all-good.example', $listed);

        // Pinned separately from the parity assertion above, which would be
        // satisfied by the SQL and the classifier agreeing on a WRONG answer.
        // These two carry a stale passing `*_verified_at` beside a failing check,
        // so a filter that read the timestamp — as the SQL fallback does when no
        // check row exists — would call them healthy.
        self::assertContains('stale-spf-timestamp.example', $listed, 'A record that used to work and is broken now must stay in triage.');
        self::assertContains('every-record-broken.example', $listed);
    }

    #[Test]
    public function everyDomainAppearsUnderExactlyOneChip(): void
    {
        // The three chips partition the team's domains. A domain in two of them
        // (or in none) means one of the SQL arms drifted from its complement.
        $teamId = $this->seedEveryHealthShape();
        $query = $this->getService(GetDomainOverview::class);

        $all = array_map(static fn ($row): string => $row->domainName, $query->forTeams([$teamId]));
        sort($all);

        $partitioned = [];
        foreach (DomainHealthFilter::cases() as $status) {
            foreach ($query->forTeams([$teamId], $status) as $row) {
                $partitioned[] = $row->domainName;
            }
        }
        sort($partitioned);

        self::assertSame($all, $partitioned);
    }

    /**
     * A domain per shape that changes the classifier's answer. The axes covered:
     * DMARC-verified or not; each of the four protocols individually failing its
     * newest check; a failing check overriding a stale passing timestamp (for one
     * protocol and for all of them); the legacy no-check-row fallback with the MX
     * snapshot score above the floor, below it, and absent entirely; and a pass
     * rate above the threshold, below it, and absent.
     *
     * Not a full cross-product — six inputs with three states each is hundreds of
     * rows — but every arm of `classifyOverview()` and every arm of the SQL it is
     * transcribed into is exercised, in both directions where direction matters.
     * Names describe the shape so a failure says which one drifted.
     */
    private function seedEveryHealthShape(): string
    {
        $em = $this->getService(EntityManagerInterface::class);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'Health Parity',
            slug: 'health-parity-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $em->persist($team);

        // Never verified — nothing else can outrank that.
        $this->domain($em, $team, 'never-verified.example', dmarcVerified: false);

        // Verified, every check row valid, healthy pass rate.
        $allGood = $this->domain($em, $team, 'all-good.example', dmarcVerified: true);
        $this->seedChecks($em, $allGood);
        $this->seedReport($em, $allGood, pass: 99, fail: 1);

        // Verified, every check row valid, pass rate below the threshold.
        $failingMail = $this->domain($em, $team, 'failing-mail.example', dmarcVerified: true);
        $this->seedChecks($em, $failingMail);
        $this->seedReport($em, $failingMail, pass: 3, fail: 7);

        // Verified, every check row valid, no reports at all — correctly set up
        // and simply waiting, which is Healthy, not Attention.
        $awaiting = $this->domain($em, $team, 'awaiting-first-report.example', dmarcVerified: true);
        $this->seedChecks($em, $awaiting);

        // One broken record each, everything else excellent.
        foreach ([[DnsCheckType::Spf, 'broken-spf.example'], [DnsCheckType::Dkim, 'broken-dkim.example'], [DnsCheckType::Dmarc, 'broken-dmarc.example'], [DnsCheckType::Mx, 'broken-mx.example']] as [$broken, $name]) {
            $domain = $this->domain($em, $team, $name, dmarcVerified: true);
            $this->seedChecks($em, $domain, [$broken]);
            $this->seedReport($em, $domain, pass: 99, fail: 1);
        }

        // A FAILING check beside a STALE PASSING timestamp — the case
        // `DomainHealthClassifier::protocolConfigured()` calls "the whole point":
        // `CheckDomainDnsHandler` only ever SETS `*_verified_at` and never clears
        // it, so a record that broke last month keeps the timestamp from when it
        // last worked, and reading the timestamp alone declares the domain
        // healthy. The check verdict must win in the NEGATIVE direction, in SQL
        // (`COALESCE(false, true)` → false) exactly as it does in PHP
        // (`false ?? true` → false). The `broken-*` domains above exercise
        // `COALESCE(false, false)`, which cannot tell the two apart.
        $staleSpf = $this->domain($em, $team, 'stale-spf-timestamp.example', dmarcVerified: true, spfVerified: true, dkimVerified: true);
        $this->seedChecks($em, $staleSpf, [DnsCheckType::Spf]);
        $this->seedSnapshot($em, $staleSpf, mxScore: 95);
        $this->seedReport($em, $staleSpf, pass: 99, fail: 1);

        // The same override across every protocol at once: three timestamps and
        // a healthy MX score all say "fine", every current check says otherwise.
        $allStale = $this->domain($em, $team, 'every-record-broken.example', dmarcVerified: true, spfVerified: true, dkimVerified: true);
        $this->seedChecks($em, $allStale, [DnsCheckType::Spf, DnsCheckType::Dkim, DnsCheckType::Dmarc, DnsCheckType::Mx]);
        $this->seedSnapshot($em, $allStale, mxScore: 95);
        $this->seedReport($em, $allStale, pass: 99, fail: 1);

        // No check rows at all — the legacy derivation is all the classifier has.
        // Verified-at columns set for SPF/DKIM/DMARC and an MX score above the
        // configured floor means "configured" under the fallback.
        $legacyOk = $this->domain($em, $team, 'legacy-configured.example', dmarcVerified: true, spfVerified: true, dkimVerified: true);
        $this->seedSnapshot($em, $legacyOk, mxScore: 95);
        $this->seedReport($em, $legacyOk, pass: 99, fail: 1);

        // Same, but the snapshot's MX score is below the floor.
        $legacyWeakMx = $this->domain($em, $team, 'legacy-weak-mx.example', dmarcVerified: true, spfVerified: true, dkimVerified: true);
        $this->seedSnapshot($em, $legacyWeakMx, mxScore: 40);
        $this->seedReport($em, $legacyWeakMx, pass: 99, fail: 1);

        // Same, but no snapshot has ever been written — the nightly sweep has not
        // run yet, so MX is unproven and Healthy must not be claimed.
        $legacyNoSnapshot = $this->domain($em, $team, 'legacy-no-snapshot.example', dmarcVerified: true, spfVerified: true, dkimVerified: true);
        $this->seedReport($em, $legacyNoSnapshot, pass: 99, fail: 1);

        // Verified for DMARC only — SPF and DKIM were never verified and have no
        // check rows either.
        $this->domain($em, $team, 'dmarc-only.example', dmarcVerified: true);

        $em->flush();

        return $team->id->toString();
    }

    private function domain(
        EntityManagerInterface $em,
        Team $team,
        string $name,
        bool $dmarcVerified,
        bool $spfVerified = false,
        bool $dkimVerified = false,
    ): MonitoredDomain {
        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: $name,
            createdAt: new \DateTimeImmutable('-60 days'),
            dmarcVerifiedAt: $dmarcVerified ? new \DateTimeImmutable('-30 days') : null,
        );
        $domain->spfVerifiedAt = $spfVerified ? new \DateTimeImmutable('-30 days') : null;
        $domain->dkimVerifiedAt = $dkimVerified ? new \DateTimeImmutable('-30 days') : null;
        $em->persist($domain);

        return $domain;
    }

    /**
     * Exactly one newest check row per protocol. Written as one row per type so
     * the query's `DISTINCT ON (type) … ORDER BY type, checked_at DESC` has no
     * tie to break — two rows per type sharing a `checked_at` would make the
     * fixture itself nondeterministic.
     *
     * @param list<DnsCheckType> $invalidTypes
     */
    private function seedChecks(EntityManagerInterface $em, MonitoredDomain $domain, array $invalidTypes = []): void
    {
        foreach ([DnsCheckType::Spf, DnsCheckType::Dkim, DnsCheckType::Dmarc, DnsCheckType::Mx] as $type) {
            $check = new DnsCheckResult(
                id: Uuid::uuid7(),
                monitoredDomain: $domain,
                type: $type,
                checkedAt: new \DateTimeImmutable('-1 hour'),
                rawRecord: 'record',
                isValid: !in_array($type, $invalidTypes, true),
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

    private function seedSnapshot(EntityManagerInterface $em, MonitoredDomain $domain, int $mxScore): void
    {
        $em->persist(new DomainHealthSnapshot(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            grade: 'A',
            score: 95,
            spfScore: 100,
            dkimScore: 100,
            dmarcScore: 100,
            mxScore: $mxScore,
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
