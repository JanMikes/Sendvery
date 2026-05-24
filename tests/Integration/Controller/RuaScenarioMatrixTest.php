<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\DnsCheckResult;
use App\Entity\MonitoredDomain;
use App\Tests\Fixtures\Persona;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\TestSupport\FallbackCalloutStripping;
use App\Tests\WebTestCase;
use App\Value\DnsCheckType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * TASK-100: integration coverage that the per-domain matrix on /app/mailboxes
 * renders scenario-aware action copy for each of the three RUA scenarios, and
 * that scenario (b) PointsAtSendvery never leaks an unqualified mailbox CTA
 * into the stripped page surface guarded by the TASK-090 regression net.
 */
final class RuaScenarioMatrixTest extends WebTestCase
{
    use FallbackCalloutStripping;

    #[Test]
    public function noRecordDomainShowsPublishRuaActionAndDmarcMissingBadge(): void
    {
        $data = $this->bootPersonaOnly();
        $persona = $data['persona'];
        assert(null !== $persona->domain);

        // Persist a DnsCheckResult with rawRecord === null → scenario NoRecord.
        $this->persistDnsCheck($data['em'], $persona->domain, null);

        $data['client']->request('GET', '/app/mailboxes');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString('DMARC missing', $body);
        self::assertStringContainsString('Publish RUA', $body);
    }

    #[Test]
    public function pointsAtSendveryDomainShowsIngestingBadgeAndNoCta(): void
    {
        $data = $this->bootPersonaOnly();
        $persona = $data['persona'];
        assert(null !== $persona->domain);

        $this->persistDnsCheck(
            $data['em'],
            $persona->domain,
            'v=DMARC1; p=none; rua=mailto:reports@sendvery.com',
        );

        $data['client']->request('GET', '/app/mailboxes');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString('Ingesting via DNS (Sendvery)', $body);
        // Scenario (b) explicitly suppresses any "Connect" CTA on this row.
        self::assertStringContainsString('RUA points at', $body);
    }

    #[Test]
    public function pointsAtExternalDomainShowsConnectThisInboxAndRepointLink(): void
    {
        $data = $this->bootPersonaOnly();
        $persona = $data['persona'];
        assert(null !== $persona->domain);

        $this->persistDnsCheck(
            $data['em'],
            $persona->domain,
            'v=DMARC1; p=none; rua=mailto:reports@acme.com',
        );

        $data['client']->request('GET', '/app/mailboxes');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString('Configured for external inbox', $body);
        self::assertStringContainsString('reports@acme.com', $body);
        self::assertStringContainsString('Connect this inbox', $body);
        self::assertStringContainsString('Or repoint to Sendvery', $body);
    }

    /**
     * Regression for the TASK-090 fallback-callout ban: the scenario (b)
     * matrix row must NOT introduce any banned mailbox-first copy outside
     * the fallback callout. "Connect this inbox" is qualified copy — it's
     * scenario-(c) only, never on a scenario-(b) row.
     */
    #[Test]
    public function pointsAtSendveryRowDoesNotLeakUnqualifiedMailboxCopyOutsideFallback(): void
    {
        $data = $this->bootPersonaOnly();
        $persona = $data['persona'];
        assert(null !== $persona->domain);

        $this->persistDnsCheck(
            $data['em'],
            $persona->domain,
            'v=DMARC1; p=none; rua=mailto:reports@sendvery.com',
        );

        $data['client']->request('GET', '/app/mailboxes');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();

        $stripped = $this->stripFallbackCalloutAndGlobalDropdown($body);

        self::assertStringNotContainsString('Connect a mailbox', $stripped);
        self::assertDoesNotMatchRegularExpression('/\bConnect mailbox\b/', $stripped);
        self::assertStringNotContainsString('Add mailbox', $stripped);
        // Scenario (b) row specifically: no "Connect this inbox" either —
        // that's reserved for scenario (c).
        self::assertStringNotContainsString('Connect this inbox', $stripped);
    }

    private function persistDnsCheck(
        EntityManagerInterface $em,
        MonitoredDomain $domain,
        ?string $rawRecord,
    ): DnsCheckResult {
        $check = new DnsCheckResult(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            type: DnsCheckType::Dmarc,
            checkedAt: new \DateTimeImmutable(),
            rawRecord: $rawRecord,
            isValid: null !== $rawRecord,
            issues: [],
            details: [],
            previousRawRecord: null,
            hasChanged: false,
            isFirstCheck: true,
        );
        $check->popEvents();
        $em->persist($check);
        $em->flush();

        return $check;
    }

    /**
     * @return array{client: KernelBrowser, em: EntityManagerInterface, persona: Persona}
     */
    private function bootPersonaOnly(): array
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $fixtures = TestFixtures::fromContainer(self::getContainer());

        $persona = $fixtures->persona()
            ->emailPrefix('rua-sc-'.substr(Uuid::uuid7()->toString(), 0, 6))
            ->withDomain('rua-sc.example')
            ->build();

        $client->loginUser($persona->user);

        return [
            'client' => $client,
            'em' => $em,
            'persona' => $persona,
        ];
    }
}
