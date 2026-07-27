<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Dashboard;

use App\Entity\DnsCheckResult;
use App\Entity\MonitoredDomain;
use App\Tests\Fixtures\Persona;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use App\Value\DnsCheckType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Manual DNS re-check (DEC-059).
 *
 * Someone who has just published a DNS record must be able to force a check
 * instead of waiting for the 03:00 cron — and because that check runs live
 * SPF/DKIM/DMARC/MX lookups inside the web request, it is capped at one run per
 * domain per 3 minutes so a signed-in user cannot make Sendvery hammer
 * third-party resolvers.
 *
 * Hitting the cap is normal impatience, never an error: the button says how
 * long is left, and a POST that slips through anyway (stale second tab) is
 * answered with a neutral notice rather than an exception.
 */
final class DomainDnsRecheckTest extends WebTestCase
{
    #[Test]
    public function theHealthPageOffersAManualRecheck(): void
    {
        // The page a user lands on right after publishing a record. It carried
        // no re-check control at all until DEC-059, leaving the 03:00 cron as
        // the only way to find out whether the record took.
        [$client, $persona] = $this->signedInPersonaWithCheckedDomain();

        $crawler = $client->request('GET', $this->healthPath($persona));

        self::assertResponseIsSuccessful();
        $button = $crawler->filter('[data-testid="recheck-dns-button"]');
        self::assertCount(1, $button, 'The health page must offer a manual DNS re-check.');
        self::assertNull($button->attr('disabled'), 'With no recent check the control is clickable.');
        self::assertCount(
            1,
            $crawler->filter(sprintf('form[action="/app/domains/%s/reverify"]', $this->domainId($persona))),
            'The control posts to the re-check endpoint for this domain.',
        );
    }

    #[Test]
    public function theDnsHistoryPageOffersAManualRecheckOnceChecksExist(): void
    {
        // The DNS-history lede promises "any time you click Re-check now", but
        // the only button lived in the no-history empty state — so the promise
        // went stale the moment a single check had run.
        [$client, $persona] = $this->signedInPersonaWithCheckedDomain();

        $crawler = $client->request('GET', sprintf('/app/domains/%s/dns-history', $this->domainId($persona)));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="recheck-dns-button"]'), 'The DNS history page must offer the re-check its own copy promises.');
    }

    #[Test]
    public function theDashboardNextStepRecheckIsClickableRatherThanA405(): void
    {
        // The overview "Next step" card offered "Re-check DNS" as a plain link,
        // but the endpoint only accepts POST — following the dashboard's single
        // most prominent call to action produced Method Not Allowed.
        $client = self::createClient();
        $persona = TestFixtures::fromContainer(self::getContainer())->persona()->build();
        assert(null !== $persona->domain);
        $client->loginUser($persona->user);

        $crawler = $client->request('GET', '/app');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Verify DNS for', $crawler->filter('body')->text(), 'This persona must be in the verify-DNS next-step state for this test to mean anything.');
        self::assertCount(
            0,
            $crawler->filter(sprintf('a[href="/app/domains/%s/reverify"]', $this->domainId($persona))),
            'A re-check must never be offered as a GET link — the endpoint is POST-only.',
        );
        self::assertGreaterThan(
            0,
            $crawler->filter(sprintf('form[action="/app/domains/%s/reverify"]', $this->domainId($persona)))->count(),
            'The next-step call to action must post to the re-check endpoint.',
        );
    }

    #[Test]
    public function clickingRecheckRunsAFreshDnsCheck(): void
    {
        [$client, $persona] = $this->signedInPersonaWithCheckedDomain();
        $domainId = $this->domainId($persona);
        $before = $this->countChecks($domainId);

        $client->request('POST', sprintf('/app/domains/%s/reverify', $domainId));

        self::assertResponseRedirects();
        self::assertGreaterThan(
            $before,
            $this->countChecks($domainId),
            'A re-check must actually write fresh DNS check results — that is the whole point of not waiting for the cron.',
        );
    }

    #[Test]
    public function asecondRecheckWithinTheCooldownIsThrottledInsteadOfRunningMoreLookups(): void
    {
        [$client, $persona] = $this->signedInPersonaWithCheckedDomain();
        $domainId = $this->domainId($persona);
        $reverifyPath = sprintf('/app/domains/%s/reverify', $domainId);

        $client->request('POST', $reverifyPath);
        $afterFirst = $this->countChecks($domainId);

        $client->request('POST', $reverifyPath, server: ['HTTP_REFERER' => 'http://localhost'.$this->healthPath($persona)]);

        self::assertResponseRedirects($this->healthPath($persona), 302, 'A throttled re-check still returns the user to the page they clicked from — it is not an error page.');
        self::assertSame(
            $afterFirst,
            $this->countChecks($domainId),
            'A second re-check inside the cooldown must not perform any DNS lookups — throttling it is the whole protection.',
        );
    }

    #[Test]
    public function aThrottledRecheckExplainsTheWaitNeutrallyRatherThanErroring(): void
    {
        [$client, $persona] = $this->signedInPersonaWithCheckedDomain();
        $reverifyPath = sprintf('/app/domains/%s/reverify', $this->domainId($persona));
        $healthUrl = 'http://localhost'.$this->healthPath($persona);

        $client->request('POST', $reverifyPath);
        $client->request('POST', $reverifyPath, server: ['HTTP_REFERER' => $healthUrl]);
        $crawler = $client->followRedirect();

        self::assertResponseIsSuccessful();
        $notice = $crawler->filter('[data-testid="domain-recheck-notice"]');
        self::assertCount(1, $notice, 'Being early must be explained, not silently ignored — otherwise the click appears to do nothing.');
        self::assertStringContainsString('you can run another check in', $notice->text(), 'The notice names the remaining wait.');
        self::assertStringNotContainsString('alert-error', (string) $notice->attr('class'), 'Impatience is not an error state — the notice must read as neutral information.');
    }

    #[Test]
    public function theButtonReportsTheRemainingWaitWhileTheDomainIsCoolingDown(): void
    {
        [$client, $persona] = $this->signedInPersonaWithCheckedDomain();

        $client->request('POST', sprintf('/app/domains/%s/reverify', $this->domainId($persona)));
        $crawler = $client->request('GET', $this->healthPath($persona));

        $button = $crawler->filter('[data-testid="recheck-dns-button"]');
        self::assertCount(1, $button);
        self::assertNotNull($button->attr('disabled'), 'A re-check that would only be bounced must not be offered as a live click.');
        self::assertStringContainsString('Re-check available in', $button->text(), 'The user is told when the control comes back rather than being left to guess.');
    }

    #[Test]
    public function renderingTheButtonDoesNotSpendTheDomainsRecheckBudget(): void
    {
        [$client, $persona] = $this->signedInPersonaWithCheckedDomain();
        $domainId = $this->domainId($persona);
        $before = $this->countChecks($domainId);

        // Visiting pages that carry the control must be free: if painting the
        // button consumed a token, simply browsing the domain workspace would
        // lock the user out of the action they came for.
        $client->request('GET', $this->healthPath($persona));
        $client->request('GET', sprintf('/app/domains/%s/dns-history', $domainId));
        $client->request('GET', sprintf('/app/domains/%s', $domainId));

        $client->request('POST', sprintf('/app/domains/%s/reverify', $domainId));

        self::assertGreaterThan($before, $this->countChecks($domainId), 'Three page views must leave the re-check budget untouched.');
    }

    #[Test]
    public function aManualRecheckAlsoRefreshesTheHealthScoreRatherThanWaitingForTheNightlyCron(): void
    {
        // Someone who has just published a record clicks re-check to see the
        // effect. Updating only the per-record checklist while the grade and
        // category bars kept yesterday's numbers left two panels on the same
        // page disagreeing about the same domain.
        [$client, $persona] = $this->signedInPersonaWithCheckedDomain();
        $domainId = $this->domainId($persona);

        $database = self::getContainer()->get(Connection::class);
        assert($database instanceof Connection);
        self::assertSame(
            0,
            $this->countSnapshots($database, $domainId),
            'Precondition: the fixture has no health snapshot yet.',
        );

        $client->request('POST', sprintf('/app/domains/%s/reverify', $domainId));

        self::assertSame(
            1,
            $this->countSnapshots($database, $domainId),
            'A manual re-check must record a fresh health snapshot so the score surfaces update immediately.',
        );
    }

    private function countSnapshots(Connection $database, string $domainId): int
    {
        return (int) $database->fetchOne(
            'SELECT COUNT(*) FROM domain_health_snapshot WHERE monitored_domain_id = :domainId',
            ['domainId' => $domainId],
        );
    }

    /**
     * @return array{0: KernelBrowser, 1: Persona}
     */
    private function signedInPersonaWithCheckedDomain(): array
    {
        $client = self::createClient();

        // Every throttle test here spans more than one request and asserts on
        // rate-limiter state carried between them. That state lives in the
        // cache.rate_limiter pool, whose filesystem namespace is seeded by the
        // compiled container — so a kernel reboot that happens to rebuild the
        // container lands the second request on a DIFFERENT, empty namespace
        // and the cooldown silently vanishes. That made these tests pass alone
        // and fail intermittently in a full suite run. Pinning the kernel keeps
        // both requests on one container, so the limiter is exercised for real
        // instead of racing the cache namespace.
        $client->disableReboot();

        $persona = TestFixtures::fromContainer(self::getContainer())->persona()->build();
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $domain = $em->find(MonitoredDomain::class, $persona->domain->id);
        assert($domain instanceof MonitoredDomain);

        // A domain that has already been checked once is the state the button
        // was missing from — the "first check pending" banners carried their
        // own control, the settled state carried none.
        $check = new DnsCheckResult(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            type: DnsCheckType::Dmarc,
            checkedAt: new \DateTimeImmutable('-1 day'),
            rawRecord: 'v=DMARC1; p=none;',
            isValid: true,
            issues: [],
            details: [],
            previousRawRecord: null,
            hasChanged: false,
        );
        $check->popEvents();
        $em->persist($check);
        $em->flush();

        $client->loginUser($persona->user);

        return [$client, $persona];
    }

    private function domainId(Persona $persona): string
    {
        assert(null !== $persona->domain);

        return $persona->domain->id->toString();
    }

    private function healthPath(Persona $persona): string
    {
        return sprintf('/app/domains/%s/health', $this->domainId($persona));
    }

    private function countChecks(string $domainId): int
    {
        $connection = self::getContainer()->get(Connection::class);
        assert($connection instanceof Connection);

        return (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM dns_check_result WHERE monitored_domain_id = ?',
            [$domainId],
        );
    }
}
