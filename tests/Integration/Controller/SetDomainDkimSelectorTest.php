<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\MonitoredDomain;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\DomCrawler\Crawler;

/**
 * TASK-146 — Per-domain DKIM selector preference.
 *
 * Covers the dashboard form on /app/domains/{id} that lets a team teach
 * Sendvery the right DKIM selector when the canonical brute-force registry
 * doesn't include it. Behaviour pinned: the form renders pre-filled,
 * setting a value persists it, clearing it reverts to brute-force,
 * malformed labels are rejected, and idempotent re-submits don't fire
 * duplicate DNS re-verifications.
 */
final class SetDomainDkimSelectorTest extends WebTestCase
{
    #[Test]
    public function domainDetailRendersDkimSelectorFormPreFilledWithSavedValue(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $persona->domain->dkimSelector = 'custom-selector';
        $em->flush();

        $client->loginUser($persona->user);
        $crawler = $client->request('GET', sprintf('/app/domains/%s', $persona->domain->id->toString()));

        self::assertResponseIsSuccessful();

        $input = $crawler->filter('[data-testid="domain-dkim-selector-input"]');
        self::assertCount(1, $input, 'The DKIM selector form must render on the domain detail page.');
        self::assertSame('custom-selector', (string) $input->attr('value'), 'The DKIM selector input must be pre-filled with the saved value so the team can see what is currently in effect.');
    }

    #[Test]
    public function teamCanSetDomainDkimSelectorForCustomKeys(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $client->loginUser($persona->user);

        $this->submitSelectorForm($client, $persona->domain->id->toString(), 'selector1');

        $em->clear();
        $domain = $em->find(MonitoredDomain::class, $persona->domain->id);
        self::assertNotNull($domain);
        self::assertSame('selector1', $domain->dkimSelector, 'The team-submitted selector must persist so the next DNS check uses it instead of brute-forcing the canonical registry.');
    }

    #[Test]
    public function clearingDkimSelectorRevertsToBruteForce(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $persona->domain->dkimSelector = 'old-selector';
        $em->flush();

        $client->loginUser($persona->user);

        $this->submitSelectorForm($client, $persona->domain->id->toString(), '');

        $em->clear();
        $domain = $em->find(MonitoredDomain::class, $persona->domain->id);
        self::assertNotNull($domain);
        self::assertNull($domain->dkimSelector, 'Submitting an empty selector must clear the preference so the brute-force fallback runs again on the next DNS check.');
    }

    #[Test]
    public function whitespaceOnlySelectorIsNormalisedToCleared(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $persona->domain->dkimSelector = 'old-selector';
        $em->flush();

        $client->loginUser($persona->user);

        $this->submitSelectorForm($client, $persona->domain->id->toString(), '   ');

        $em->clear();
        $domain = $em->find(MonitoredDomain::class, $persona->domain->id);
        self::assertNotNull($domain);
        self::assertNull($domain->dkimSelector, 'A whitespace-only selector must be normalised to NULL — visually identical to clearing the field, so the system must treat it the same way.');
    }

    #[Test]
    public function selectorValidationRejectsMalformedDnsLabel(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $persona->domain->dkimSelector = 'starting-good';
        $em->flush();

        $client->loginUser($persona->user);

        // A dot makes the input a multi-label name rather than a single DNS
        // label — DKIM selectors are a single label, not a domain.
        $this->submitSelectorForm($client, $persona->domain->id->toString(), 'with.dots');

        $em->clear();
        $domain = $em->find(MonitoredDomain::class, $persona->domain->id);
        self::assertNotNull($domain);
        self::assertSame('starting-good', $domain->dkimSelector, 'A malformed selector must be rejected without overwriting the existing saved value — silent corruption of the team\'s preference would be worse than refusing the input.');
    }

    #[Test]
    public function leadingHyphenSelectorIsRejected(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $client->loginUser($persona->user);

        $this->submitSelectorForm($client, $persona->domain->id->toString(), '-starts-with-hyphen');

        $em->clear();
        $domain = $em->find(MonitoredDomain::class, $persona->domain->id);
        self::assertNotNull($domain);
        self::assertNull($domain->dkimSelector, 'A DNS label cannot start with a hyphen per RFC 1035 — the validation must reject it.');
    }

    #[Test]
    public function resubmittingUnchangedSelectorDoesNotFireRedundantDnsCheck(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $persona->domain->dkimSelector = 'selector1';
        $em->flush();

        $countBefore = (int) $em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM dns_check_result WHERE monitored_domain_id = ?',
            [$persona->domain->id->toString()],
        );

        $client->loginUser($persona->user);
        $this->submitSelectorForm($client, $persona->domain->id->toString(), 'selector1');

        $em->clear();
        $countAfter = (int) $em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM dns_check_result WHERE monitored_domain_id = ?',
            [$persona->domain->id->toString()],
        );

        self::assertSame(
            $countBefore,
            $countAfter,
            'Re-submitting the same selector must not trigger a DNS re-check — the idempotency guard must short-circuit before calling CheckDomainDnsHandler. Without this guard, a careless user double-clicking the Save button would burn DNS lookups for no change.',
        );
    }

    #[Test]
    public function requestWithoutCsrfTokenIsRefused(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        $client->loginUser($persona->user);

        $client->request('POST', sprintf('/app/domains/%s/dkim-selector', $persona->domain->id->toString()), [
            'selector' => 'selector1',
        ]);

        self::assertSame(
            403,
            $client->getResponse()->getStatusCode(),
            'A POST without a valid CSRF token must be refused — saving a DKIM selector is a write operation and CSRF protection is non-negotiable.',
        );
    }

    private function submitSelectorForm(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, string $domainId, string $selector): void
    {
        // Fetch the form to harvest the CSRF token, then POST the value.
        $crawler = $client->request('GET', sprintf('/app/domains/%s', $domainId));
        self::assertResponseIsSuccessful();

        $token = $this->extractCsrfToken($crawler);

        $client->request('POST', sprintf('/app/domains/%s/dkim-selector', $domainId), [
            '_csrf_token' => $token,
            'selector' => $selector,
        ]);

        // The controller redirects to /app/domains/{id} on success or invalid input.
        self::assertSame(302, $client->getResponse()->getStatusCode());
    }

    private function extractCsrfToken(Crawler $crawler): string
    {
        $token = $crawler->filter('form[action$="/dkim-selector"] input[name="_csrf_token"]')->attr('value');
        self::assertNotNull($token);

        return (string) $token;
    }
}
