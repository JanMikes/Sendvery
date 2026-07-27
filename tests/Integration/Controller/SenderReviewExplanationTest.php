<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use App\Value\SenderReviewState;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * A user landing on a domain with freshly discovered senders must be able to
 * answer three questions without leaving the page: what does this badge mean,
 * is something expected of me, and how do I decide?
 *
 * Before this, five amber "Unknown" pills at 100% DKIM/SPF came with an
 * Authorize button and no explanation anywhere, and the only link to the
 * explainer article sat behind volume thresholds most senders never cross.
 */
final class SenderReviewExplanationTest extends WebTestCase
{
    /**
     * The reported case: several Seznam addresses, all passing, none reviewed,
     * each too quiet for the advisor to have an opinion about.
     *
     * @return array{client: KernelBrowser, domainId: string}
     */
    private function bootQuietUnreviewedSenders(): array
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());

        $persona = $fixtures->persona()
            ->emailPrefix('sender-explain')
            ->withKnownSender('77.75.78.89', totalMessages: 3, organization: 'Seznam', hostname: 'mxb.seznam.cz')
            ->withKnownSender('77.75.78.90', totalMessages: 2, organization: 'Seznam', hostname: 'mxb-1-910.seznam.cz')
            ->build();

        $client->loginUser($persona->user);
        assert(null !== $persona->domain);

        return ['client' => $client, 'domainId' => $persona->domain->id->toString()];
    }

    #[Test]
    public function theSenderInventoryTellsTheUserHowManySendersAreWaitingOnThem(): void
    {
        $boot = $this->bootQuietUnreviewedSenders();

        $crawler = $boot['client']->request('GET', '/app/domains/'.$boot['domainId'].'/senders');

        self::assertResponseIsSuccessful();
        $cta = $crawler->filter('[data-testid="sender-review-cta"]');
        self::assertGreaterThan(0, $cta->count(), 'A domain with unreviewed senders must state the expected action.');
        self::assertStringContainsString(
            '2 senders waiting for your review',
            $crawler->filter('[data-testid="sender-review-cta-headline"]')->text(),
        );
        self::assertStringContainsString(
            '/senders?filter=needs_review',
            (string) $crawler->filter('[data-testid="sender-review-cta-primary"]')->attr('href'),
        );
    }

    /**
     * The advisor is silent below five messages in 30 days, which is exactly the
     * case the user hit. The call to action must NOT be gated on it.
     */
    #[Test]
    public function theCallToActionAppearsEvenWhenSendersAreTooQuietForARecommendation(): void
    {
        $boot = $this->bootQuietUnreviewedSenders();

        $crawler = $boot['client']->request('GET', '/app/domains/'.$boot['domainId'].'/senders');

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('[data-testid="sender-review-cta"]')->count());
        self::assertCount(
            0,
            $crawler->filter('[data-testid="sender-action-callout"]'),
            'Low-volume senders get no per-row recommendation — which is why the page-level CTA has to exist.',
        );
    }

    #[Test]
    public function theDomainDetailPageStatesTheSameExpectedAction(): void
    {
        $boot = $this->bootQuietUnreviewedSenders();

        $crawler = $boot['client']->request('GET', '/app/domains/'.$boot['domainId']);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            '2 senders waiting for your review',
            $crawler->filter('[data-testid="sender-review-cta-headline"]')->text(),
        );
    }

    #[Test]
    public function theStatusLegendExplainsEveryBadgeAndIsOpenWhileSomethingNeedsDeciding(): void
    {
        $boot = $this->bootQuietUnreviewedSenders();

        $crawler = $boot['client']->request('GET', '/app/domains/'.$boot['domainId'].'/senders');

        self::assertResponseIsSuccessful();
        $legend = $crawler->filter('[data-testid="sender-status-legend"]');
        self::assertGreaterThan(0, $legend->count(), 'The page must explain its own statuses.');
        self::assertNotNull($legend->attr('open'), 'The explanation must be expanded while senders await review.');

        $text = $legend->text();
        self::assertStringContainsString('Authorized', $text);
        self::assertStringContainsString('Needs review', $text);
        self::assertStringContainsString('Not authorized', $text);
        self::assertStringContainsString('How do I verify a sender is really mine?', $text);
        self::assertStringContainsString('What happens if I do nothing?', $text);
    }

    /**
     * The knowledge-base article must be reachable from the page itself, not
     * only from the threshold-gated per-row callout.
     */
    #[Test]
    public function theExplainerArticleIsLinkedFromThePageNotOnlyFromTheRecommendationCallout(): void
    {
        $boot = $this->bootQuietUnreviewedSenders();

        $crawler = $boot['client']->request('GET', '/app/domains/'.$boot['domainId'].'/senders');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'authorizing-senders-explained',
            (string) $crawler->filter('[data-testid="sender-legend-kb-link"]')->attr('href'),
        );
        self::assertStringContainsString(
            'authorizing-senders-explained',
            (string) $crawler->filter('[data-testid="sender-review-cta-kb-link"]')->attr('href'),
        );
    }

    #[Test]
    public function everySenderBadgeCarriesAnExplanationOfWhatItMeans(): void
    {
        $boot = $this->bootQuietUnreviewedSenders();

        $crawler = $boot['client']->request('GET', '/app/domains/'.$boot['domainId'].'/senders');

        self::assertResponseIsSuccessful();
        $badge = $crawler->filter('[data-testid="sender-status-badge"]')->first();
        self::assertSame(SenderReviewState::NeedsReview->value, $badge->attr('data-sender-state'));
        self::assertSame(SenderReviewState::NeedsReview->meaning(), $badge->attr('title'));
    }

    /**
     * The vocabulary fix: the word on the badge, the word on the tab and the
     * word on the button all have to be the same word.
     */
    #[Test]
    public function theBadgeTheTabAndTheButtonAllUseTheSameWords(): void
    {
        $boot = $this->bootQuietUnreviewedSenders();

        $crawler = $boot['client']->request('GET', '/app/domains/'.$boot['domainId'].'/senders');

        self::assertResponseIsSuccessful();
        $body = (string) $boot['client']->getResponse()->getContent();

        self::assertStringContainsString('Needs review', $crawler->filter('[data-testid="sender-tab-needs-review"]')->text());
        self::assertStringNotContainsString('Unauthorized', $body, '"Unauthorized" was one of four words for two states.');
        self::assertStringNotContainsString('Mark unknown', $body, 'The negative action is "Mark not authorized" everywhere.');
    }

    #[Test]
    public function anUndecidedSenderOffersBothVerdictsNotOnlyAuthorize(): void
    {
        $boot = $this->bootQuietUnreviewedSenders();

        $crawler = $boot['client']->request('GET', '/app/domains/'.$boot['domainId'].'/senders');

        self::assertResponseIsSuccessful();
        $actions = $crawler->filter('tbody tr')->first()->text();
        self::assertStringContainsString('Authorize', $actions);
        self::assertStringContainsString('Not authorized', $actions);
    }

    #[Test]
    public function aDomainWithEverySenderDecidedShowsNoCallToAction(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());

        $persona = $fixtures->persona()
            ->emailPrefix('sender-settled')
            ->withKnownSender('203.0.113.1', organization: 'Seznam', reviewState: SenderReviewState::Authorized)
            ->withKnownSender('203.0.113.2', reviewState: SenderReviewState::NotAuthorized)
            ->build();
        $client->loginUser($persona->user);
        assert(null !== $persona->domain);

        $crawler = $client->request('GET', '/app/domains/'.$persona->domain->id->toString().'/senders');

        self::assertResponseIsSuccessful();
        self::assertCount(
            0,
            $crawler->filter('[data-testid="sender-review-cta"]'),
            'Nothing awaits a decision, so nothing should be demanded of the user.',
        );
        self::assertNull(
            $crawler->filter('[data-testid="sender-status-legend"]')->attr('open'),
            'With every sender decided the explanation collapses out of the way.',
        );
    }

    /**
     * A rejected sender and an unreviewed one used to render the same amber
     * "Unknown" badge. They are different decisions and must read differently.
     */
    #[Test]
    public function aRejectedSenderReadsDifferentlyFromOneNobodyHasDecidedAbout(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());

        $persona = $fixtures->persona()
            ->emailPrefix('sender-tristate')
            ->withKnownSender('203.0.113.11', reviewState: SenderReviewState::NeedsReview)
            ->withKnownSender('203.0.113.12', reviewState: SenderReviewState::NotAuthorized)
            ->withKnownSender('203.0.113.13', reviewState: SenderReviewState::Authorized)
            ->build();
        $client->loginUser($persona->user);
        assert(null !== $persona->domain);

        $crawler = $client->request('GET', '/app/domains/'.$persona->domain->id->toString().'/senders');

        self::assertResponseIsSuccessful();
        $states = $crawler->filter('[data-testid="sender-status-badge"]')->each(
            static fn ($node): ?string => $node->attr('data-sender-state'),
        );

        self::assertContains('needs_review', $states);
        self::assertContains('not_authorized', $states);
        self::assertContains('authorized', $states);
    }

    #[Test]
    public function theNeedsReviewFilterShowsOnlySendersNobodyHasDecidedAbout(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());

        $persona = $fixtures->persona()
            ->emailPrefix('sender-filter')
            ->withKnownSender('203.0.113.21', reviewState: SenderReviewState::NeedsReview)
            ->withKnownSender('203.0.113.22', reviewState: SenderReviewState::NotAuthorized)
            ->withKnownSender('203.0.113.23', reviewState: SenderReviewState::Authorized)
            ->build();
        $client->loginUser($persona->user);
        assert(null !== $persona->domain);
        $path = '/app/domains/'.$persona->domain->id->toString().'/senders';

        $crawler = $client->request('GET', $path.'?filter=needs_review');

        self::assertResponseIsSuccessful();
        self::assertSame(['needs_review'], $crawler->filter('[data-testid="sender-status-badge"]')->each(
            static fn ($node): ?string => $node->attr('data-sender-state'),
        ));
    }

    #[Test]
    public function theNotAuthorizedFilterShowsOnlySendersTheUserRejected(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());

        $persona = $fixtures->persona()
            ->emailPrefix('sender-filter-rejected')
            ->withKnownSender('203.0.113.31', reviewState: SenderReviewState::NeedsReview)
            ->withKnownSender('203.0.113.32', reviewState: SenderReviewState::NotAuthorized)
            ->build();
        $client->loginUser($persona->user);
        assert(null !== $persona->domain);

        $crawler = $client->request('GET', '/app/domains/'.$persona->domain->id->toString().'/senders?filter=not_authorized');

        self::assertResponseIsSuccessful();
        self::assertSame(['not_authorized'], $crawler->filter('[data-testid="sender-status-badge"]')->each(
            static fn ($node): ?string => $node->attr('data-sender-state'),
        ));
    }

    /**
     * `?filter=unauthorized` predates the tri-state split. Older links and
     * bookmarks still point at it, so it must keep returning everything that is
     * not authorized — and say so rather than silently activating a tab that
     * means something narrower.
     */
    #[Test]
    public function theLegacyUnauthorizedFilterStillReturnsBothUndecidedAndRejectedSenders(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());

        $persona = $fixtures->persona()
            ->emailPrefix('sender-legacy-filter')
            ->withKnownSender('203.0.113.41', reviewState: SenderReviewState::NeedsReview)
            ->withKnownSender('203.0.113.42', reviewState: SenderReviewState::NotAuthorized)
            ->withKnownSender('203.0.113.43', reviewState: SenderReviewState::Authorized)
            ->build();
        $client->loginUser($persona->user);
        assert(null !== $persona->domain);

        $crawler = $client->request('GET', '/app/domains/'.$persona->domain->id->toString().'/senders?filter=unauthorized');

        self::assertResponseIsSuccessful();
        $states = $crawler->filter('[data-testid="sender-status-badge"]')->each(
            static fn ($node): ?string => $node->attr('data-sender-state'),
        );
        self::assertCount(2, $states);
        self::assertContains('needs_review', $states);
        self::assertContains('not_authorized', $states);
        self::assertGreaterThan(
            0,
            $crawler->filter('[data-testid="sender-legacy-unauthorized-tab"]')->count(),
            'A filter that spans two states must label itself, not pretend to be one of them.',
        );
    }

    #[Test]
    public function theAuthorizedFilterStillReturnsOnlyAuthorizedSenders(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());

        $persona = $fixtures->persona()
            ->emailPrefix('sender-authorized-filter')
            ->withKnownSender('203.0.113.51', reviewState: SenderReviewState::NeedsReview)
            ->withKnownSender('203.0.113.52', reviewState: SenderReviewState::Authorized)
            ->build();
        $client->loginUser($persona->user);
        assert(null !== $persona->domain);

        $crawler = $client->request('GET', '/app/domains/'.$persona->domain->id->toString().'/senders?filter=authorized');

        self::assertResponseIsSuccessful();
        self::assertSame(['authorized'], $crawler->filter('[data-testid="sender-status-badge"]')->each(
            static fn ($node): ?string => $node->attr('data-sender-state'),
        ));
    }

    /**
     * The table is filtered; the call to action is not. Landing on the
     * Authorized tab must not report "0 senders waiting".
     */
    #[Test]
    public function theCallToActionCountsTheWholeDomainNotTheFilteredTable(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());

        $persona = $fixtures->persona()
            ->emailPrefix('sender-cta-scope')
            ->withKnownSender('203.0.113.61', reviewState: SenderReviewState::NeedsReview)
            ->withKnownSender('203.0.113.62', reviewState: SenderReviewState::Authorized)
            ->build();
        $client->loginUser($persona->user);
        assert(null !== $persona->domain);

        $crawler = $client->request('GET', '/app/domains/'.$persona->domain->id->toString().'/senders?filter=authorized');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            '1 sender waiting for your review',
            $crawler->filter('[data-testid="sender-review-cta-headline"]')->text(),
        );
    }
}
