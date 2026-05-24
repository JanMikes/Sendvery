<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\DnsCheckResult;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use App\Value\DnsCheckType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * TASK-091 — verifies the dashboard "Publish a DMARC RUA record" next-step
 * dismissal flow: a valid POST sets `team.ingestion_recommendation_dismissed_at`
 * and after dismissal the demoted fallback "Connect a mailbox (fallback)" branch
 * takes over the Next Step card.
 */
final class DismissIngestionRecommendationTest extends WebTestCase
{
    /**
     * Build a persona whose verification status is OK (DMARC verified +
     * first report received) and whose domain is younger than 7 days so the
     * NextActionResolver lands on the PublishRuaRecord branch.
     *
     * @return array{client: KernelBrowser, em: EntityManagerInterface, team: Team, domain: MonitoredDomain}
     */
    private function bootPublishRuaPersona(string $emailPrefix): array
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $fixtures = TestFixtures::fromContainer(self::getContainer());

        $persona = $fixtures->persona()
            ->emailPrefix($emailPrefix.'-'.substr(uniqid('', true), -6))
            ->withDomain('ingrec-'.substr(uniqid('', true), -6).'.example')
            ->build();
        assert(null !== $persona->domain);

        // Push verification status into "Ok" so the resolver skips
        // VerifyDns / WaitForReports and lands on the ingestion branch.
        $persona->domain->dmarcVerifiedAt = new \DateTimeImmutable('-2 days');
        $persona->domain->firstReportAt = new \DateTimeImmutable('-1 day');

        // Latest DNS check passes so DomainVerificationEvaluator returns Ok.
        $em->persist(new DnsCheckResult(
            id: Uuid::uuid7(),
            monitoredDomain: $persona->domain,
            type: DnsCheckType::Dmarc,
            checkedAt: new \DateTimeImmutable('-1 hour'),
            rawRecord: 'v=DMARC1; p=none;',
            isValid: true,
            issues: [],
            details: [],
            previousRawRecord: null,
            hasChanged: false,
            isFirstCheck: false,
        ));
        $em->flush();

        $client->loginUser($persona->user);

        return [
            'client' => $client,
            'em' => $em,
            'team' => $persona->team,
            'domain' => $persona->domain,
        ];
    }

    #[Test]
    public function dismissWithValidCsrfRedirectsAndSetsDismissedAt(): void
    {
        $data = $this->bootPublishRuaPersona('ingrec');
        $client = $data['client'];

        // Render the overview first so a real CSRF token gets seeded into the
        // session. The PublishRuaRecord branch renders the dismiss form.
        $client->request('GET', '/app');
        $crawler = $client->getCrawler();
        $token = $crawler->filter('form[action="/app/ingestion-recommendation/dismiss"] input[name="_csrf_token"]')->attr('value');
        self::assertNotEmpty($token);

        $client->request('POST', '/app/ingestion-recommendation/dismiss', [
            '_csrf_token' => $token,
        ]);

        self::assertResponseRedirects('/app');

        $em = $data['em'];
        $teamId = $data['team']->id;
        $em->clear();
        $team = $em->find(Team::class, $teamId);
        self::assertNotNull($team);
        self::assertNotNull($team->ingestionRecommendationDismissedAt);
    }

    #[Test]
    public function dismissWithInvalidCsrfReturns403(): void
    {
        $data = $this->bootPublishRuaPersona('ingrec-bad-csrf');

        $data['client']->request('POST', '/app/ingestion-recommendation/dismiss', [
            '_csrf_token' => 'definitely-not-a-real-token',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function dismissRouteRejectsGet(): void
    {
        $data = $this->bootPublishRuaPersona('ingrec-get');

        $data['client']->request('GET', '/app/ingestion-recommendation/dismiss');

        self::assertResponseStatusCodeSame(405);
    }

    #[Test]
    public function publishRuaRecordRendersForVerifiedDomainWithinSevenDays(): void
    {
        $data = $this->bootPublishRuaPersona('ingrec-publish');

        $data['client']->request('GET', '/app');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString('Publish a DMARC RUA record', $body);
        self::assertStringContainsString('Prefer to connect a mailbox instead? (fallback)', $body);
        self::assertStringContainsString('/app/ingestion-recommendation/dismiss', $body);
    }

    #[Test]
    public function connectMailboxFallbackRendersAfterDismissal(): void
    {
        $data = $this->bootPublishRuaPersona('ingrec-after-dismiss');
        $em = $data['em'];
        $teamId = $data['team']->id;

        $team = $em->find(Team::class, $teamId);
        self::assertNotNull($team);
        $team->dismissIngestionRecommendation(new \DateTimeImmutable('-1 hour'));
        $em->flush();

        $data['client']->request('GET', '/app');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString('Connect a mailbox (fallback)', $body);
        self::assertStringNotContainsString('Publish a DMARC RUA record', $body);
        // The demoted fallback variant does NOT render the secondary CTA /
        // dismiss form — only the PublishRuaRecord branch does.
        self::assertStringNotContainsString('Prefer to connect a mailbox instead? (fallback)', $body);
    }
}
