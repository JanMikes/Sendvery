<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Tests\Fixtures\Persona;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;

/**
 * Every confirmation a dashboard controller flashes has to reach the page it
 * redirects to.
 *
 * Flash rendering used to be a block copy-pasted into individual page templates.
 * Six had it; every page added afterwards did not — so whether the user saw
 * "Note saved." or "DMARC policy published: p=quarantine" depended entirely on
 * which template the controller happened to redirect to. Around twenty messages
 * reached nobody, including every confirmation on the domain detail, billing,
 * sender inventory and preferences pages.
 *
 * The layout owns it now, so a new page cannot forget: it never has to remember.
 */
final class FlashMessagesAreAlwaysRenderedTest extends WebTestCase
{
    #[Test]
    public function anErrorRedirectedToTheBillingPageIsShownToTheUser(): void
    {
        // Driven through the real controller: "Manage subscription" with no
        // subscription flashes an error and redirects to a template that had no
        // flash block, so the user was bounced back with no explanation at all.
        [$client] = $this->signedIn();

        $client->request('GET', '/app/settings/billing/manage');
        $crawler = $client->followRedirect();

        self::assertSelectorExists('.alert-error');
        self::assertStringContainsString('No active subscription to manage.', $crawler->filter('.alert-error')->text());
    }

    #[Test]
    public function anErrorRedirectedToTheDomainDetailPageIsShownToTheUser(): void
    {
        // Same shape on the busiest destination in the app: eight controllers
        // redirect here with a confirmation, and none of them ever appeared.
        [$client, $persona] = $this->signedIn('free');
        assert(null !== $persona->domain);

        $client->request('GET', sprintf('/app/domains/%s/export/pdf', $persona->domain->id->toString()));
        $crawler = $client->followRedirect();

        self::assertSelectorExists('.alert-error');
        self::assertStringContainsString('PDF export requires a Personal plan', $crawler->filter('.alert-error')->text());
    }

    #[Test]
    public function anUnrecognisedFlashTypeIsShownNeutrallyRatherThanSwallowed(): void
    {
        // A type nobody mapped is still a message somebody wrote for the user.
        // Dropping it is how this bug happened; painting it red or green would
        // assert a meaning we do not have.
        [$client] = $this->signedIn();

        $crawler = $this->requestWithPendingFlash($client, '/app', 'plan_change_pending', 'Your plan change is being processed.');

        self::assertSelectorExists('.alert-info');
        self::assertStringContainsString('Your plan change is being processed.', $crawler->filter('.alert-info')->text());
        self::assertSelectorNotExists('.alert-error');
    }

    #[Test]
    public function aFlashIsRenderedOnceNotTwice(): void
    {
        // The pages that already had their own block now rely on the layout;
        // leaving both in place would have doubled every message.
        [$client] = $this->signedIn();

        $crawler = $this->requestWithPendingFlash($client, '/app/alerts', 'success', 'Marked 3 alerts as read.');

        self::assertCount(1, $crawler->filter('.alert-success'));
    }

    #[Test]
    public function teamMessagesUseTheSameGlobalConventionAsEverythingElse(): void
    {
        // team_* used to be its own namespace rendered by its own block inside
        // the settings card — a convention that predates there being a shared
        // region at all. One region, one set of types.
        [$client] = $this->signedIn();

        $crawler = $this->requestWithPendingFlash($client, '/app/team', 'success', 'Invitation sent to sam@example.com.');

        self::assertStringContainsString('Invitation sent to sam@example.com.', $crawler->filter('body')->text());
        self::assertCount(1, $crawler->filter('.alert-success'), 'Rendered once, by the shared region.');
    }

    #[Test]
    public function aPublicPageRendersFlashesToo(): void
    {
        // The sign-in page is on the marketing layout, not the dashboard one,
        // and used to render only `success` — so an error redirected there had
        // nowhere to land. Both layouts share the region now.
        $client = self::createClient();
        $client->disableReboot();

        $crawler = $this->requestWithPendingFlash($client, '/login', 'error', 'That sign-in link has expired.');

        self::assertSelectorExists('.alert-error');
        self::assertStringContainsString('That sign-in link has expired.', $crawler->filter('.alert-error')->text());
    }

    /**
     * @return array{KernelBrowser, Persona}
     */
    private function signedIn(string $plan = 'pro'): array
    {
        $client = self::createClient();
        // Flashes are session state carried across two requests, so both have to
        // land on the same kernel and the same session storage.
        $client->disableReboot();
        $persona = TestFixtures::fromContainer(self::getContainer())->persona()->plan($plan)->build();
        $client->loginUser($persona->user);

        return [$client, $persona];
    }

    /**
     * Park a flash in the signed-in session, then load the page — the shape a
     * redirect leaves behind, without needing a controller that raises exactly
     * the type under test.
     */
    private function requestWithPendingFlash(
        KernelBrowser $client,
        string $path,
        string $type,
        string $message,
    ): Crawler {
        $client->request('GET', $path);

        $session = $client->getRequest()->getSession();
        assert($session instanceof FlashBagAwareSessionInterface);
        $session->getFlashBag()->add($type, $message);
        $session->save();

        return $client->request('GET', $path);
    }
}
