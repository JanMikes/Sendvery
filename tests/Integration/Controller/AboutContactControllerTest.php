<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Founder contact surface — /about/contact.
 *
 * The page is the public face of "there is a human running this product."
 * Behaviour pinned here covers both the happy-path (form submission lands
 * in the founder's inbox + a DB-first audit row), the spam-mitigation
 * layers (honeypot + time-trap + per-IP rate-limit — NO 3rd-party CAPTCHA
 * because that contradicts the open-source / self-hostable positioning),
 * SEO baseline (BreadcrumbList JSON-LD + sitemap), and the cross-page
 * affordances that make the founder channel reachable from every public
 * surface (top-nav + footer link).
 *
 * Sibling card pins TASK-160's GitHub-issues route — the two-channel trust
 * pattern (business → founder email; engineering → GitHub Issues) lives
 * on the same template so visitors self-select the right channel.
 */
final class AboutContactControllerTest extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Wipe the rate-limiter cache pool between tests. The pool is
        // filesystem-backed in test (see when@test framework.cache) so
        // its token-bucket state survives services_resetter — but it would
        // ALSO survive across tests if we didn't clear it here, making
        // the 6th-request test flaky depending on the suite order.
        self::bootKernel();
        $pool = self::getContainer()->get('cache.rate_limiter');
        assert($pool instanceof \Psr\Cache\CacheItemPoolInterface);
        $pool->clear();
        self::ensureKernelShutdown();
    }

    #[Test]
    public function pageRendersForAnonymousVisitor(): void
    {
        $client = self::createClient();
        $client->request('GET', '/about/contact');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Talk directly to the founder.');
        self::assertSelectorExists('a[href="mailto:jan.mikes@sendvery.com"]');
    }

    #[Test]
    public function pageRendersForSignedInVisitor(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();

        $client->loginUser($persona->user);
        $client->request('GET', '/about/contact');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Talk directly to the founder.');
    }

    #[Test]
    public function visitorCanSubmitContactFormAndFounderGetsEmail(): void
    {
        $client = self::createClient();
        // The auto-redirect (302 → GET /about/contact?sent=1) would reboot
        // the kernel between sending the email and reading the mailer
        // listener, dropping the in-memory event we want to assert about.
        // followRedirects(false) keeps us on the 302 response.
        $client->followRedirects(false);

        $token = $this->harvestCsrfToken($client);

        $client->request('POST', '/about/contact', [
            '_csrf_token' => $token,
            'renderedAt' => (string) $this->pastTimestamp(),
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'subject' => 'Can Sendvery monitor 200 domains?',
            'message' => 'We are an agency with about 200 client domains; can the Personal plan stretch or do I need Agency?',
            'website' => '',
        ]);

        self::assertResponseRedirects('/about/contact?sent=1');
        self::assertEmailCount(1, null, 'A legitimate submission must produce exactly one email — the founder notification. Sending more would risk noise, sending none would mean the founder never hears about the inquiry.');

        $email = self::getMailerMessages()[0];
        assert($email instanceof \Symfony\Component\Mime\Email);
        self::assertSame('jan.mikes@sendvery.com', $email->getTo()[0]->getAddress(), 'The contact form must email the founder directly — NOT support@ or hello@. The whole point of /about/contact is that there is a named human on the other end.');
        self::assertStringContainsString('Can Sendvery monitor 200 domains?', (string) $email->getSubject(), 'The subject must include the visitor-supplied subject so Jan can triage at a glance.');
        self::assertStringContainsString('Ada Lovelace', (string) $email->getSubject(), 'The subject must include the sender name so Jan can identify the inquiry without opening it.');
        $textBody = (string) ($email->getTextBody() ?? '');
        self::assertStringContainsString('ada@example.com', $textBody, 'The body must include the sender email so Jan can reply.');
        self::assertStringContainsString('about 200 client domains', $textBody, 'The body must include the full message text.');

        // Reply-To routes a direct hit-Reply back to the visitor, so Jan
        // does not have to copy-paste the email out of the body.
        self::assertSame('ada@example.com', $email->getReplyTo()[0]->getAddress(), 'Reply-To must point to the visitor email so hitting Reply goes back to them, not to the founder address.');
    }

    #[Test]
    public function formSubmissionPersistsContactInquiryRow(): void
    {
        $client = self::createClient();

        $token = $this->harvestCsrfToken($client);

        $client->request('POST', '/about/contact', [
            '_csrf_token' => $token,
            'renderedAt' => (string) $this->pastTimestamp(),
            'name' => 'Grace Hopper',
            'email' => 'grace@example.com',
            'subject' => 'Self-host help',
            'message' => 'Trying to self-host on a Hetzner box — where do you keep the migrations?',
            'website' => '',
        ]);

        self::assertResponseRedirects('/about/contact?sent=1');

        $connection = self::getContainer()->get(Connection::class);
        assert($connection instanceof Connection);

        $row = $connection->fetchAssociative(
            'SELECT name, email, subject, message FROM contact_inquiry WHERE email = ?',
            ['grace@example.com'],
        );

        self::assertIsArray($row, 'Submitting the form must persist a row in contact_inquiry — DB-first audit trail. Email delivery is best-effort; the DB row is the source of truth.');
        self::assertSame('Grace Hopper', $row['name']);
        self::assertSame('Self-host help', $row['subject']);
        self::assertStringContainsString('Hetzner box', $row['message']);
    }

    #[Test]
    public function honeypotFieldSilentlyRejectsBotSubmissions(): void
    {
        $client = self::createClient();

        $token = $this->harvestCsrfToken($client);

        $client->request('POST', '/about/contact', [
            '_csrf_token' => $token,
            'renderedAt' => (string) $this->pastTimestamp(),
            'name' => 'Spam Bot',
            'email' => 'spam@example.com',
            'subject' => 'Buy our SEO services',
            'message' => 'Visit our amazing offer at www.example.com — guaranteed first-page results!',
            // Bots fill every input they see. Humans cannot see this field
            // (display:none + tabindex=-1 + aria-hidden) so any non-empty
            // value is dispositive evidence of a script.
            'website' => 'http://spam.example.com',
        ]);

        // Pretend-accept so the bot does not learn its honeypot tripped
        // and rotate to a new submission technique. Silent reject is the
        // whole point.
        self::assertResponseRedirects('/about/contact?sent=1', 302, 'Honeypot-tripped submissions must return the same success redirect a real submission would — never an error code, or bots will detect the honeypot and adapt.');
        self::assertEmailCount(0, null, 'A honeypot-tripped submission must NOT email Jan — the form would become a spam relay otherwise.');

        $connection = self::getContainer()->get(Connection::class);
        assert($connection instanceof Connection);
        $count = (int) $connection->fetchOne('SELECT COUNT(*) FROM contact_inquiry WHERE email = ?', ['spam@example.com']);
        self::assertSame(0, $count, 'A honeypot-tripped submission must NOT persist to the database — otherwise contact_inquiry becomes a spam log.');
    }

    #[Test]
    public function timeTrapRejectsSubmissionsArrivingFasterThanHuman(): void
    {
        $client = self::createClient();

        $token = $this->harvestCsrfToken($client);

        $now = self::getContainer()->get(ClockInterface::class)->now()->getTimestamp();

        // renderedAt within the last second — a human cannot fill four
        // fields (name + email + subject + 10-char message) in under 2
        // seconds; a script can submit in milliseconds.
        $client->request('POST', '/about/contact', [
            '_csrf_token' => $token,
            'renderedAt' => (string) $now,
            'name' => 'Fast Bot',
            'email' => 'fast@example.com',
            'subject' => 'Hi',
            'message' => 'This is a valid-length message.',
            'website' => '',
        ]);

        self::assertResponseRedirects('/about/contact?sent=1', 302, 'Time-trap-tripped submissions must also pretend-accept — same silent-reject reasoning as the honeypot.');
        self::assertEmailCount(0, null, 'A submission arriving < 2s after page render must NOT email Jan.');

        $connection = self::getContainer()->get(Connection::class);
        assert($connection instanceof Connection);
        $count = (int) $connection->fetchOne('SELECT COUNT(*) FROM contact_inquiry WHERE email = ?', ['fast@example.com']);
        self::assertSame(0, $count, 'A time-trap-tripped submission must NOT persist to the database.');
    }

    #[Test]
    public function missingRenderTimestampIsRejected(): void
    {
        $client = self::createClient();

        $token = $this->harvestCsrfToken($client);

        // Bots that strip the hidden field entirely look as suspicious as
        // those that submit too fast — same silent-reject treatment.
        $client->request('POST', '/about/contact', [
            '_csrf_token' => $token,
            // 'renderedAt' deliberately absent
            'name' => 'Stripped Bot',
            'email' => 'stripped@example.com',
            'subject' => 'Hi',
            'message' => 'This is a valid-length message.',
            'website' => '',
        ]);

        self::assertResponseRedirects('/about/contact?sent=1');
        self::assertEmailCount(0, null, 'Submissions missing the render-timestamp hidden field must be silently dropped — only a script would strip it.');
    }

    #[Test]
    public function rateLimiterBlocksSixthRequestWithinAnHour(): void
    {
        $client = self::createClient();

        // Five accepted submissions in a row exhaust the token bucket.
        for ($i = 1; $i <= 5; ++$i) {
            $token = $this->harvestCsrfToken($client);

            $client->request('POST', '/about/contact', [
                '_csrf_token' => $token,
                'renderedAt' => (string) $this->pastTimestamp(),
                'name' => sprintf('Visitor %d', $i),
                'email' => sprintf('visitor%d@example.com', $i),
                'subject' => sprintf('Question %d', $i),
                'message' => 'Hello, I have a legitimate question about your service.',
                'website' => '',
            ]);

            self::assertResponseRedirects('/about/contact?sent=1');
        }

        // Sixth submission from the same IP within the same hour: blocked.
        $token = $this->harvestCsrfToken($client);

        $client->request('POST', '/about/contact', [
            '_csrf_token' => $token,
            'renderedAt' => (string) $this->pastTimestamp(),
            'name' => 'Visitor 6',
            'email' => 'visitor6@example.com',
            'subject' => 'One more',
            'message' => 'Hello, I have a legitimate question about your service.',
            'website' => '',
        ]);

        // The sixth submission re-renders the form with an error flash —
        // it is NOT pretend-accepted (legitimate users need to know they
        // are throttled so they can email Jan directly).
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-error', 'too many messages', 'The sixth submission within the rate-limit window must surface a visible error so a legitimate user being throttled understands what happened and can fall back to the mailto link.');

        $connection = self::getContainer()->get(Connection::class);
        assert($connection instanceof Connection);
        $count = (int) $connection->fetchOne('SELECT COUNT(*) FROM contact_inquiry WHERE email = ?', ['visitor6@example.com']);
        self::assertSame(0, $count, 'The throttled sixth submission must not persist.');
    }

    #[Test]
    public function csrfTokenIsRequired(): void
    {
        $client = self::createClient();

        // No GET first — submit directly without harvesting a token.
        $client->request('POST', '/about/contact', [
            'renderedAt' => (string) $this->pastTimestamp(),
            'name' => 'No Token',
            'email' => 'notoken@example.com',
            'subject' => 'Hi',
            'message' => 'This is a valid-length message.',
            'website' => '',
        ]);

        // Without a CSRF token Symfony Security throws AccessDenied which —
        // for an anonymous visitor on a non-firewalled path — is converted
        // to a redirect to the magic-link login entry point. The dispositive
        // assertion is that the submission did NOT pretend-succeed: no
        // /about/contact?sent=1 redirect, no row in contact_inquiry, no
        // email. A malicious site CSRF-ing this form gets nothing.
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertStringNotContainsString('/about/contact?sent=1', $location, 'A POST without a valid CSRF token must NOT produce the success redirect — that would mean CSRF protection is off and a malicious site could forge submissions.');
        self::assertEmailCount(0, null, 'A POST without a valid CSRF token must not email Jan.');

        $connection = self::getContainer()->get(Connection::class);
        assert($connection instanceof Connection);
        $count = (int) $connection->fetchOne('SELECT COUNT(*) FROM contact_inquiry WHERE email = ?', ['notoken@example.com']);
        self::assertSame(0, $count, 'A POST without a valid CSRF token must not persist anything to contact_inquiry.');
    }

    #[Test]
    public function emptyMessageFailsValidationAndDoesNotEmail(): void
    {
        $client = self::createClient();

        $token = $this->harvestCsrfToken($client);

        $client->request('POST', '/about/contact', [
            '_csrf_token' => $token,
            'renderedAt' => (string) $this->pastTimestamp(),
            'name' => 'Empty Message',
            'email' => 'empty@example.com',
            'subject' => 'Test',
            'message' => '', // Empty — fails NotBlank
            'website' => '',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.alert-error', 'Validation errors must render in a visible error panel so the visitor can fix and retry.');
        self::assertEmailCount(0);
    }

    #[Test]
    public function breadcrumbListJsonLdIsPresentOnContactPage(): void
    {
        $client = self::createClient();
        $client->request('GET', '/about/contact');

        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('"@type": "BreadcrumbList"', $body, 'The contact page must emit a BreadcrumbList JSON-LD entity so Google can render SERP breadcrumb chips matching the TASK-149 pattern across the rest of the marketing site.');
        self::assertStringContainsString('"name": "Contact"', $body, 'The breadcrumb chain must end on a Contact node so the SERP chip reads correctly.');
    }

    #[Test]
    public function navContainsContactLinkForAllVisitors(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');

        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('href="/about/contact"', $body, 'The top nav must expose a Contact link from every public page — visitors landing on the homepage need a one-click route to the founder channel, not just from the About area.');
    }

    #[Test]
    public function footerContainsContactLinkInTrustColumn(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/');

        // The Trust column is the right home for Contact alongside Privacy,
        // Security, Status, Open Source — it is a trust signal, not a
        // tool or a product page.
        $contactLink = $crawler->filter('footer a[href="/about/contact"]');
        self::assertGreaterThanOrEqual(1, $contactLink->count(), 'The footer must link to /about/contact from every public page so the founder channel is one click away regardless of where the visitor lands.');
    }

    #[Test]
    public function githubIssuesCardLinksToCanonicalRepoCasing(): void
    {
        $client = self::createClient();
        $client->request('GET', '/about/contact');

        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString(
            'https://github.com/janmikes/Sendvery/issues/new',
            $body,
            'The GitHub-issues card must link directly to the new-issue page (not the repo root, not /issues), and it MUST use canonical capital-S casing `Sendvery`. The lowercase URL 301-redirects, costing an extra hop GitHub does not need to take.',
        );
    }

    #[Test]
    public function githubIssuesCardOpensInNewTabSafely(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/about/contact');

        $link = $crawler->filter('a[href="https://github.com/janmikes/Sendvery/issues/new"]')->first();
        self::assertCount(1, $link, 'The GitHub-issues card must contain exactly one canonical issue-new link.');
        self::assertSame('_blank', $link->attr('target'), 'Off-domain links should open in a new tab so the visitor does not lose Sendvery context.');
        self::assertStringContainsString('noopener', (string) $link->attr('rel'), 'target="_blank" without rel="noopener" leaks window.opener to the destination site — security baseline.');
    }

    #[Test]
    public function sitemapIncludesContactRoute(): void
    {
        $client = self::createClient();
        $client->request('GET', '/sitemap.xml');

        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('/about/contact', $content, 'The sitemap must include /about/contact so Google can discover the founder-channel page and surface it for branded queries like "sendvery contact".');
    }

    #[Test]
    public function honeypotFieldIsHiddenFromSightedUsersAndAssistiveTech(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/about/contact');

        // The honeypot wrapper must be display:none + aria-hidden so neither
        // sighted users nor screen readers ever interact with it. Visible
        // honeypots get filled by accessibility-tool users and produce
        // false-positive spam classifications.
        $wrapper = $crawler->filter('div[aria-hidden="true"][style*="display:none"]');
        self::assertGreaterThanOrEqual(1, $wrapper->count(), 'The honeypot must live inside a div with both display:none AND aria-hidden="true" — visible honeypots get filled by assistive-tech users, producing accessibility-driven false positives.');

        $input = $crawler->filter('input[name="website"]');
        self::assertCount(1, $input);
        self::assertSame('-1', $input->attr('tabindex'), 'The honeypot input must carry tabindex="-1" so it is skipped by keyboard navigation.');
        self::assertSame('off', $input->attr('autocomplete'), 'autocomplete="off" prevents browser password managers from auto-filling the honeypot field for legitimate users.');
    }

    #[Test]
    public function pageDeclaresFounderInLedeSoVisitorKnowsWhoTheyAreEmailing(): void
    {
        $client = self::createClient();
        $client->request('GET', '/about/contact');

        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Jan Mikeš', $body, 'The lede paragraph must name the founder by full name — anonymity defeats the whole point of a founder-channel page.');
        self::assertStringContainsString('sole founder', $body, 'The lede must frame Jan as the sole founder so the visitor understands this is not a contact-us-form-routed-to-a-helpdesk.');
    }

    /**
     * A "renderedAt" value the controller will treat as past the 2-second
     * human-fill threshold. Wall-clock minus 5 seconds is well past the
     * trap regardless of when the test runs.
     */
    private function pastTimestamp(): int
    {
        return self::getContainer()->get(ClockInterface::class)->now()->getTimestamp() - 5;
    }

    private function harvestCsrfToken(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/about/contact');
        self::assertResponseIsSuccessful();

        return $this->extractCsrfToken($crawler);
    }

    private function extractCsrfToken(Crawler $crawler): string
    {
        $node = $crawler->filter('input[name="_csrf_token"]')->first();
        self::assertCount(1, $node, 'The contact form must render a CSRF token input.');
        $value = $node->attr('value');
        self::assertNotNull($value);

        return $value;
    }
}
