<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\DnsCheckResult;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use App\Value\DnsCheckType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;

/**
 * TASK-166 — DKIM selector UX improvement.
 *
 * Covers the redesigned DKIM selector card on /app/domains/{id}: the
 * detection status display, provider-aware suggestions, saved-vs-detected
 * mismatch warning, and reset-to-auto-detect button.
 */
final class DkimDetectionDisplayTest extends WebTestCase
{
    #[Test]
    public function domainDetailShowsDetectedSelectorFromLastDnsCheck(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $this->insertDkimCheckResult($em, $persona->domain, selector: 'google', keyFound: true, keyType: 'rsa', keyBits: 2048);

        $client->loginUser($persona->user);
        $crawler = $client->request('GET', sprintf('/app/domains/%s', $persona->domain->id->toString()));

        self::assertResponseIsSuccessful();

        $status = $crawler->filter('[data-testid="dkim-detection-status"]');
        self::assertCount(1, $status, 'The DKIM detection status must render when a DNS check has been recorded.');
        self::assertStringContainsString('Found', $status->text(), 'A successful DKIM check must show "Found" status so the user knows their key was detected.');

        $detectedSelector = $crawler->filter('[data-testid="dkim-detected-selector"]');
        self::assertCount(1, $detectedSelector, 'The detected selector label must be visible.');
        self::assertSame('google', $detectedSelector->text(), 'The detected selector must match the value from the last DNS check.');
    }

    #[Test]
    public function domainDetailShowsNotFoundWhenNoDkimKeyDetected(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $this->insertDkimCheckResult($em, $persona->domain, selector: 'default', keyFound: false, detectedProviders: ['Google']);

        $client->loginUser($persona->user);
        $crawler = $client->request('GET', sprintf('/app/domains/%s', $persona->domain->id->toString()));

        self::assertResponseIsSuccessful();

        $status = $crawler->filter('[data-testid="dkim-detection-status"]');
        self::assertStringContainsString('Not found', $status->text(), 'A failed DKIM check must show "Not found" so the user understands no key was detected.');
        self::assertStringContainsString('Google', $status->text(), 'The detected email provider must be named so the user knows which provider was identified.');
    }

    #[Test]
    public function providerAwareSuggestionsRenderWhenProvidersDetected(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $this->insertDkimCheckResult($em, $persona->domain, selector: 'google', keyFound: true, keyType: 'rsa', keyBits: 2048, detectedProviders: ['Google']);

        $client->loginUser($persona->user);
        $crawler = $client->request('GET', sprintf('/app/domains/%s', $persona->domain->id->toString()));

        self::assertResponseIsSuccessful();

        $suggestions = $crawler->filter('[data-testid="dkim-suggested-selectors"]');
        self::assertCount(1, $suggestions, 'Provider-aware selector suggestions must render when providers are detected from MX records.');

        $chips = $crawler->filter('[data-testid="dkim-suggestion-chip"]');
        self::assertGreaterThan(0, $chips->count(), 'At least one suggestion chip must render for the detected provider.');
        self::assertSame('google', $chips->first()->text(), 'The first suggestion must be the primary selector for the detected provider.');
    }

    #[Test]
    public function mismatchWarningRendersWhenSavedSelectorDiffersFromDetected(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $persona->domain->dkimSelector = 'wrong-selector';
        $em->flush();

        $this->insertDkimCheckResult($em, $persona->domain, selector: 'google', keyFound: true, keyType: 'rsa', keyBits: 2048);

        $client->loginUser($persona->user);
        $crawler = $client->request('GET', sprintf('/app/domains/%s', $persona->domain->id->toString()));

        self::assertResponseIsSuccessful();

        $mismatch = $crawler->filter('[data-testid="dkim-selector-mismatch"]');
        self::assertCount(1, $mismatch, 'A mismatch warning must render when the saved selector differs from the detected one — this signals a likely configuration error.');
        self::assertStringContainsString('wrong-selector', $mismatch->text());
        self::assertStringContainsString('google', $mismatch->text());
    }

    #[Test]
    public function noMismatchWarningWhenSavedMatchesDetected(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $persona->domain->dkimSelector = 'google';
        $em->flush();

        $this->insertDkimCheckResult($em, $persona->domain, selector: 'google', keyFound: true, keyType: 'rsa', keyBits: 2048);

        $client->loginUser($persona->user);
        $crawler = $client->request('GET', sprintf('/app/domains/%s', $persona->domain->id->toString()));

        self::assertResponseIsSuccessful();

        $mismatch = $crawler->filter('[data-testid="dkim-selector-mismatch"]');
        self::assertCount(0, $mismatch, 'No mismatch warning when saved selector matches detected — the user\'s config is correct.');
    }

    #[Test]
    public function resetToAutoDetectButtonRendersWhenSelectorIsSaved(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $persona->domain->dkimSelector = 'selector1';
        $em->flush();

        $client->loginUser($persona->user);
        $crawler = $client->request('GET', sprintf('/app/domains/%s', $persona->domain->id->toString()));

        self::assertResponseIsSuccessful();

        $resetBtn = $crawler->filter('[data-testid="dkim-reset-auto-detect"]');
        self::assertCount(1, $resetBtn, 'The reset-to-auto-detect button must appear when a selector is saved so the user can revert to brute-force detection.');
    }

    #[Test]
    public function resetToAutoDetectButtonHiddenWhenNoSelectorSaved(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        $client->loginUser($persona->user);
        $crawler = $client->request('GET', sprintf('/app/domains/%s', $persona->domain->id->toString()));

        self::assertResponseIsSuccessful();

        $resetBtn = $crawler->filter('[data-testid="dkim-reset-auto-detect"]');
        self::assertCount(0, $resetBtn, 'The reset button must be hidden when no selector is saved — auto-detect is already the active mode.');
    }

    #[Test]
    public function detectionStatusNotRenderedBeforeFirstDnsCheck(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        $client->loginUser($persona->user);
        $crawler = $client->request('GET', sprintf('/app/domains/%s', $persona->domain->id->toString()));

        self::assertResponseIsSuccessful();

        $status = $crawler->filter('[data-testid="dkim-detection-status"]');
        self::assertCount(0, $status, 'The detection status must not render before any DNS check has been recorded — avoid showing stale/empty data for new domains.');
    }

    #[Test]
    public function autoDetectModeLabelRendersWhenNoSelectorSaved(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        $client->loginUser($persona->user);
        $crawler = $client->request('GET', sprintf('/app/domains/%s', $persona->domain->id->toString()));

        self::assertResponseIsSuccessful();

        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Auto-detect mode', $body, 'When no selector is saved, the card must indicate auto-detect mode so the user understands the current behaviour.');
    }

    #[Test]
    public function lockedSelectorLabelRendersWhenSelectorIsSaved(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $persona->domain->dkimSelector = 'custom-sel';
        $em->flush();

        $client->loginUser($persona->user);
        $crawler = $client->request('GET', sprintf('/app/domains/%s', $persona->domain->id->toString()));

        self::assertResponseIsSuccessful();

        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('custom-sel', $body, 'When a selector is saved, the card must display it so the user can see what is currently in effect.');
        self::assertStringContainsString('Locked to', $body, 'The card must clearly indicate the selector is locked to a specific value.');
    }

    /**
     * @param list<string> $detectedProviders
     * @param list<string> $matchedProviders
     */
    private function insertDkimCheckResult(
        EntityManagerInterface $em,
        \App\Entity\MonitoredDomain $domain,
        string $selector = 'default',
        bool $keyFound = false,
        ?string $keyType = null,
        ?int $keyBits = null,
        array $detectedProviders = [],
        array $matchedProviders = [],
    ): void {
        $check = new DnsCheckResult(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            type: DnsCheckType::Dkim,
            checkedAt: new \DateTimeImmutable('-30 minutes'),
            rawRecord: $keyFound ? 'v=DKIM1; k=rsa; p=MIIBIjAN...' : null,
            isValid: $keyFound,
            issues: [],
            details: [
                'selector' => $selector,
                'key_type' => $keyType,
                'key_bits' => $keyBits,
                'detected_providers' => $detectedProviders,
                'matched_providers' => $matchedProviders,
            ],
            previousRawRecord: null,
            hasChanged: false,
            isFirstCheck: true,
        );
        $check->popEvents();
        $em->persist($check);
        $em->flush();
    }
}
