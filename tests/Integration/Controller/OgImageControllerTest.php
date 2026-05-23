<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\DomainHealthSnapshot;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

final class OgImageControllerTest extends WebTestCase
{
    protected function setUp(): void
    {
        // Start each test from an empty cache so we exercise the miss-path
        // and the second-request assertion below actually demonstrates a hit.
        $this->purgeCache();
    }

    protected function tearDown(): void
    {
        $this->purgeCache();
        parent::tearDown();
    }

    #[Test]
    public function returnsPngForToolSlug(): void
    {
        $client = self::createClient();
        $client->request('GET', '/og/tool/dmarc-checker');

        self::assertResponseIsSuccessful();
        self::assertSame('image/png', $client->getResponse()->headers->get('Content-Type'));
        $cacheControl = (string) $client->getResponse()->headers->get('Cache-Control');
        // Symfony normalises the directives' order, so we assert on
        // membership rather than the full canonical string.
        self::assertStringContainsString('public', $cacheControl);
        self::assertStringContainsString('max-age=2592000', $cacheControl);
        self::assertStringContainsString('immutable', $cacheControl);

        $body = $this->readResponseBytes($client->getResponse());
        // PNG magic number — confirms the body is an actual PNG, not an
        // error page accidentally served with image/png.
        self::assertStringStartsWith("\x89PNG\r\n\x1a\n", $body);
    }

    #[Test]
    public function secondRequestServesCachedBytes(): void
    {
        $client = self::createClient();

        $client->request('GET', '/og/tool/spf-checker');
        $firstBody = $this->readResponseBytes($client->getResponse());

        $client->request('GET', '/og/tool/spf-checker');
        $secondBody = $this->readResponseBytes($client->getResponse());

        // Identical inputs → identical bytes (deterministic painter +
        // cache). Drift here means a font / theme regression.
        self::assertSame(sha1($firstBody), sha1($secondBody));
    }

    #[Test]
    public function returnsPngForKbSlug(): void
    {
        $client = self::createClient();
        $client->request('GET', '/og/kb/what-is-dmarc');

        self::assertResponseIsSuccessful();
        self::assertSame('image/png', $client->getResponse()->headers->get('Content-Type'));
    }

    #[Test]
    public function returnsPngForHealthShareHash(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $persona = $fixtures->onboardedOwner();
        assert(null !== $persona->domain);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $hash = bin2hex(random_bytes(16));
        $em->persist(new DomainHealthSnapshot(
            id: Uuid::uuid7(),
            monitoredDomain: $persona->domain,
            grade: 'B',
            score: 82,
            spfScore: 90,
            dkimScore: 90,
            dmarcScore: 75,
            mxScore: 95,
            blacklistScore: 60,
            checkedAt: new \DateTimeImmutable(),
            recommendations: [],
            shareHash: $hash,
        ));
        $em->flush();

        $client->request('GET', '/og/health/'.$hash);

        self::assertResponseIsSuccessful();
        self::assertSame('image/png', $client->getResponse()->headers->get('Content-Type'));
    }

    #[Test]
    public function unknownToolSlugFallsBackToDefaultOgImage(): void
    {
        $client = self::createClient();
        $client->request('GET', '/og/tool/this-tool-does-not-exist');

        self::assertResponseRedirects();
        self::assertStringContainsString('og-default', (string) $client->getResponse()->headers->get('Location'));
    }

    #[Test]
    public function unknownTypeIsRejectedByRouteRequirements(): void
    {
        $client = self::createClient();
        $client->request('GET', '/og/banana/dmarc-checker');

        // Route requirement `tool|kb|health` blocks the request before the
        // controller is invoked, so we get a router-level 404, not the
        // controller's fallback redirect.
        self::assertResponseStatusCodeSame(404);
    }

    private function readResponseBytes(Response $response): string
    {
        // BinaryFileResponse streams its file; `$response->getContent()` is
        // empty in tests. Reading the underlying file is the test-only
        // equivalent of what FrankenPHP/Caddy hands to the browser.
        if ($response instanceof BinaryFileResponse) {
            return (string) file_get_contents($response->getFile()->getPathname());
        }

        return (string) $response->getContent();
    }

    private function purgeCache(): void
    {
        self::bootKernel();
        $projectDir = self::getContainer()->getParameter('kernel.project_dir');
        assert(is_string($projectDir));
        $cacheDir = $projectDir.'/var/og_cache';

        foreach (['tool', 'kb', 'health'] as $sub) {
            $dir = $cacheDir.'/'.$sub;
            if (!is_dir($dir)) {
                continue;
            }

            foreach (glob($dir.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }

        self::ensureKernelShutdown();
    }
}
