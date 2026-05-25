<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\BetaSignup;
use App\Tests\WebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * TASK-006 — covers the tool-result soft-conversion endpoint
 * `POST /tools/notify`. Tests every documented contract: happy path, dedup,
 * per-source rows, validation errors, unknown source, CSRF protection.
 */
final class ToolNotifyTest extends WebTestCase
{
    #[Test]
    public function happyPathPersistsBetaSignupAndRendersConfirmation(): void
    {
        $client = self::createClient();
        $token = $this->fetchCsrfToken($client);
        $email = $this->randomEmail('happy');

        $client->request('POST', '/tools/notify', [
            '_csrf_token' => $token,
            'email' => $email,
            'domain' => 'example.com',
            'source' => 'spf-result',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Check your inbox');
        self::assertSelectorTextContains('body', $email);
        self::assertSelectorTextContains('body', 'example.com');

        $row = $this->findSignup($email, 'spf-result');
        self::assertNotNull($row);
        self::assertSame('spf-result', $row->source);
        self::assertSame('domain=example.com', $row->painPoint);
        self::assertSame(1, $row->domainCount);
    }

    #[Test]
    public function responseIsAtomicTurboFrameWithSourceSpecificId(): void
    {
        $client = self::createClient();
        $token = $this->fetchCsrfToken($client);

        $client->request('POST', '/tools/notify', [
            '_csrf_token' => $token,
            'email' => $this->randomEmail('frame'),
            'domain' => 'example.com',
            'source' => 'dmarc-result',
        ]);

        self::assertResponseIsSuccessful();
        // The frame id is what Turbo uses to swap the form in place — it must
        // match the one rendered on the tool page (MonitorEmailMeMicro).
        self::assertSelectorExists('turbo-frame#tool-notify-dmarc-result');
    }

    #[Test]
    public function resubmittingSameEmailAndSourceIsIdempotent(): void
    {
        $client = self::createClient();
        $token = $this->fetchCsrfToken($client);
        $email = $this->randomEmail('dedup');

        $client->request('POST', '/tools/notify', [
            '_csrf_token' => $token,
            'email' => $email,
            'domain' => 'example.com',
            'source' => 'spf-result',
        ]);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/tools/notify', [
            '_csrf_token' => $token,
            'email' => $email,
            'domain' => 'example.com',
            'source' => 'spf-result',
        ]);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Check your inbox');

        self::assertSame(1, $this->countSignups($email, 'spf-result'));
    }

    #[Test]
    public function sameEmailDifferentSourceCreatesAdditionalRow(): void
    {
        $client = self::createClient();
        $token = $this->fetchCsrfToken($client);
        $email = $this->randomEmail('multi');

        $client->request('POST', '/tools/notify', [
            '_csrf_token' => $token,
            'email' => $email,
            'domain' => 'example.com',
            'source' => 'spf-result',
        ]);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/tools/notify', [
            '_csrf_token' => $token,
            'email' => $email,
            'domain' => 'example.com',
            'source' => 'dkim-result',
        ]);
        self::assertResponseIsSuccessful();

        self::assertSame(1, $this->countSignups($email, 'spf-result'));
        self::assertSame(1, $this->countSignups($email, 'dkim-result'));
    }

    #[Test]
    public function invalidEmailRendersErrorWithoutPersisting(): void
    {
        $client = self::createClient();
        $token = $this->fetchCsrfToken($client);

        $client->request('POST', '/tools/notify', [
            '_csrf_token' => $token,
            'email' => 'not-an-email',
            'domain' => 'example.com',
            'source' => 'spf-result',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-error', 'valid email');
        self::assertSelectorExists('form[action="/tools/notify"]');
        self::assertSame(0, $this->countSignups('not-an-email', 'spf-result'));
    }

    #[Test]
    public function missingDomainRendersError(): void
    {
        $client = self::createClient();
        $token = $this->fetchCsrfToken($client);
        $email = $this->randomEmail('nodomain');

        $client->request('POST', '/tools/notify', [
            '_csrf_token' => $token,
            'email' => $email,
            'domain' => '',
            'source' => 'spf-result',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-error', 'run a scan first');
        self::assertSame(0, $this->countSignups($email, 'spf-result'));
    }

    #[Test]
    public function malformedDomainRendersError(): void
    {
        $client = self::createClient();
        $token = $this->fetchCsrfToken($client);
        $email = $this->randomEmail('baddomain');

        $client->request('POST', '/tools/notify', [
            '_csrf_token' => $token,
            'email' => $email,
            'domain' => 'not a valid domain!!',
            'source' => 'spf-result',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-error', 'run a scan first');
        self::assertSame(0, $this->countSignups($email, 'spf-result'));
    }

    #[Test]
    public function unknownSourceReturnsBadRequest(): void
    {
        $client = self::createClient();
        $token = $this->fetchCsrfToken($client);

        $client->request('POST', '/tools/notify', [
            '_csrf_token' => $token,
            'email' => $this->randomEmail('bad-source'),
            'domain' => 'example.com',
            'source' => 'not-a-real-tool',
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertSelectorTextContains('body', 'misconfigured');
    }

    #[Test]
    public function missingCsrfTokenIsRejected(): void
    {
        $client = self::createClient();

        $client->request('POST', '/tools/notify', [
            'email' => $this->randomEmail('no-csrf'),
            'domain' => 'example.com',
            'source' => 'spf-result',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function invalidCsrfTokenIsRejected(): void
    {
        $client = self::createClient();

        $client->request('POST', '/tools/notify', [
            '_csrf_token' => 'definitely-not-real',
            'email' => $this->randomEmail('bad-csrf'),
            'domain' => 'example.com',
            'source' => 'spf-result',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function getMethodIsRejected(): void
    {
        $client = self::createClient();
        $client->request('GET', '/tools/notify');

        self::assertResponseStatusCodeSame(405);
    }

    #[Test]
    public function microFormIsRenderedOnSpfCheckerResult(): void
    {
        // Smoke: the tool template wires the component up properly so a real
        // visitor sees the form below the hard CTA. Mount the live component
        // with a domain query param so the SPF result renders and pulls in
        // both the existing CTA and the new micro-form.
        $client = self::createClient();
        $client->request('GET', '/tools/spf-checker?domain=example.com');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('turbo-frame#tool-notify-spf-result');
        self::assertSelectorExists('form[action="/tools/notify"]');
        // Hard CTA must remain — the soft form is parallel, not a replacement.
        self::assertSelectorTextContains('body', 'Stay ahead of email breakage');
    }

    /**
     * TASK-117 — the post-result hard CTA for unauthenticated visitors must name
     * the three round-4 product features by their plain-English phrases. Round 4
     * shipped DMARC report parsing, sender authorization advisor (TASK-092),
     * pass-rate regression detection (TASK-093), and scenario-aware setup
     * guidance (TASK-100) — none of which a public DMARC-record lookup tool
     * surfaces. A visitor seeing a D grade walking away thinking we're a "DNS
     * change watcher" is the conversion bug the bullets fix; pin each one.
     *
     * @return iterable<string, array{string}>
     */
    public static function ctaBulletsToolPaths(): iterable
    {
        yield 'domain-health' => ['/tools/domain-health?domain=example.com'];
        yield 'dmarc-checker' => ['/tools/dmarc-checker?domain=example.com'];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('ctaBulletsToolPaths')]
    public function postResultCtaListsThreeProductFeaturesForAnonymousVisitor(string $url): void
    {
        $client = self::createClient();
        $client->request('GET', $url);

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();

        // Bullet 1 — DMARC report parsing. The DNS-record lookup tools can't do this;
        // visitors must learn the product parses real aggregate reports.
        self::assertStringContainsString(
            "Parse every DMARC report automatically — see who's sending as you, with what pass rate",
            $body,
        );

        // Bullet 2 — pass-rate regression + DNS change alerts. The CTA used to sell only
        // DNS-change monitoring; pin the broader alert surface (TASK-092 + TASK-093).
        self::assertStringContainsString(
            'Get alerted the moment a sender starts failing or your pass rate drops',
            $body,
        );

        // Bullet 3 — scenario-aware setup guidance (TASK-100). Visitors fixing a D grade
        // need to know we hand-hold them through the fix; "no XML reading required" is
        // the literal benefit.
        self::assertStringContainsString(
            'Plain-English setup guidance — no XML reading required',
            $body,
        );
    }

    private function fetchCsrfToken(KernelBrowser $client): string
    {
        // Mount the SPF checker with a domain so the result block — and the
        // micro-form's `_csrf_token` input — actually renders on the page.
        $crawler = $client->request('GET', '/tools/spf-checker?domain=example.com');
        $token = $crawler->filter('form[action="/tools/notify"] input[name="_csrf_token"]')->attr('value');
        self::assertNotEmpty($token);

        return $token;
    }

    private function findSignup(string $email, string $source): ?BetaSignup
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $em->clear();

        return $em->getRepository(BetaSignup::class)->findOneBy([
            'email' => $email,
            'source' => $source,
        ]);
    }

    private function countSignups(string $email, string $source): int
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $em->clear();

        return (int) $em->getRepository(BetaSignup::class)->count([
            'email' => $email,
            'source' => $source,
        ]);
    }

    private function randomEmail(string $prefix): string
    {
        return $prefix.'-'.bin2hex(random_bytes(6)).'@example.com';
    }
}
