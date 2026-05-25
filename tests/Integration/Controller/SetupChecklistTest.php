<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\DnsCheckResult;
use App\Entity\Team;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use App\Value\DnsCheckType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class SetupChecklistTest extends WebTestCase
{
    /**
     * @return array{client: KernelBrowser, em: EntityManagerInterface, teamId: \Ramsey\Uuid\UuidInterface}
     */
    private function bootClientWithDomain(string $emailPrefix = 'checklist'): array
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $fixtures = TestFixtures::fromContainer(self::getContainer());

        $persona = $fixtures->persona()
            ->emailPrefix($emailPrefix.'-'.substr(uniqid('', true), -6))
            ->withDomain('checklist-'.substr(uniqid('', true), -6).'.example')
            ->build();

        $client->loginUser($persona->user);

        return [
            'client' => $client,
            'em' => $em,
            'teamId' => $persona->team->id,
        ];
    }

    #[Test]
    public function dismissWithValidCsrfRedirectsAndSetsDismissedAt(): void
    {
        $data = $this->bootClientWithDomain();
        $client = $data['client'];

        // Render the overview first so a real CSRF token gets seeded into the session.
        $client->request('GET', '/app');
        $crawler = $client->getCrawler();
        $token = $crawler->filter('form[action="/app/setup-checklist/dismiss"] input[name="_csrf_token"]')->attr('value');
        self::assertNotEmpty($token);

        $client->request('POST', '/app/setup-checklist/dismiss', [
            '_csrf_token' => $token,
        ]);

        self::assertResponseRedirects('/app');

        $em = $data['em'];
        $em->clear();
        $team = $em->find(Team::class, $data['teamId']);
        self::assertNotNull($team);
        self::assertNotNull($team->setupChecklistDismissedAt);
    }

    #[Test]
    public function dismissWithInvalidCsrfReturns403(): void
    {
        $data = $this->bootClientWithDomain('checklist-bad-csrf');

        $data['client']->request('POST', '/app/setup-checklist/dismiss', [
            '_csrf_token' => 'definitely-not-a-real-token',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function dismissRouteRejectsGet(): void
    {
        $data = $this->bootClientWithDomain('checklist-get');

        $data['client']->request('GET', '/app/setup-checklist/dismiss');

        self::assertResponseStatusCodeSame(405);
    }

    #[Test]
    public function checklistRendersOnOverviewWhenIncomplete(): void
    {
        $data = $this->bootClientWithDomain('checklist-render');

        $data['client']->request('GET', '/app');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString('Setup checklist', $body);
        self::assertStringContainsString('Finish setting up Sendvery', $body);
    }

    #[Test]
    public function checklistShowsStepsCompleteCounter(): void
    {
        $data = $this->bootClientWithDomain('checklist-counter');

        $data['client']->request('GET', '/app');

        $body = (string) $data['client']->getResponse()->getContent();
        // The persona has 1 domain → 1 of 3 steps complete.
        self::assertStringContainsString('1 of 3 steps complete', $body);
    }

    #[Test]
    public function checklistShowsHideButton(): void
    {
        $data = $this->bootClientWithDomain('checklist-hide-btn');

        $data['client']->request('GET', '/app');

        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringContainsString('Hide checklist', $body);
        self::assertStringContainsString('/app/setup-checklist/dismiss', $body);
    }

    #[Test]
    public function dismissedChecklistDoesNotRender(): void
    {
        $data = $this->bootClientWithDomain('checklist-dismissed');
        $em = $data['em'];

        $team = $em->find(Team::class, $data['teamId']);
        self::assertNotNull($team);
        $team->dismissSetupChecklist(new \DateTimeImmutable('-1 hour'));
        $em->flush();

        $data['client']->request('GET', '/app');

        self::assertResponseIsSuccessful();
        $body = (string) $data['client']->getResponse()->getContent();
        self::assertStringNotContainsString('Finish setting up Sendvery', $body);
    }

    #[Test]
    public function checklistHiddenWhenFullyComplete(): void
    {
        // DMARC verified + first report received → 3 of 3 steps → fully complete.
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $fixtures = TestFixtures::fromContainer(self::getContainer());

        $persona = $fixtures->persona()
            ->emailPrefix('checklist-done-'.substr(uniqid('', true), -6))
            ->withDomain('checklist-done-'.substr(uniqid('', true), -6).'.example')
            ->build();
        assert(null !== $persona->domain);
        $persona->domain->dmarcVerifiedAt = new \DateTimeImmutable('-5 days');
        $persona->domain->firstReportAt = new \DateTimeImmutable('-4 days');

        // A passing latest DNS check keeps the verification status from
        // bouncing into a regression scenario.
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
        $client->request('GET', '/app');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('Finish setting up Sendvery', $body);
    }

    #[Test]
    public function receiveReportsStepSwapsCopyWhenRuaPointsAtSendvery(): void
    {
        // TASK-128: when the user's published DMARC record already points
        // `rua=` at reports@sendvery.com, the third checklist step must NOT
        // tell them they could "alternatively connect a mailbox". Instead it
        // confirms reports flow in automatically and links to the DNS check.
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $fixtures = TestFixtures::fromContainer(self::getContainer());

        $persona = $fixtures->persona()
            ->emailPrefix('checklist-rua-sendvery-'.substr(uniqid('', true), -6))
            ->withDomain('rua-sendvery-'.substr(uniqid('', true), -6).'.example')
            ->build();
        assert(null !== $persona->domain);

        // Seed the most-recent DMARC check so RuaScenarioResolver classifies
        // this domain as PointsAtSendvery. Critical: no DmarcReport rows are
        // created — the "Receive your first DMARC report" step must still be
        // incomplete so it renders (otherwise the line-through styling hides
        // the description we're asserting on).
        $em->persist(new DnsCheckResult(
            id: Uuid::uuid7(),
            monitoredDomain: $persona->domain,
            type: DnsCheckType::Dmarc,
            checkedAt: new \DateTimeImmutable('-1 hour'),
            rawRecord: 'v=DMARC1; p=none; rua=mailto:reports@sendvery.com;',
            isValid: true,
            issues: [],
            details: [],
            previousRawRecord: null,
            hasChanged: false,
            isFirstCheck: true,
        ));
        $em->flush();

        $client->loginUser($persona->user);
        $client->request('GET', '/app');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();

        // The new PointsAtSendvery branch copy is present.
        self::assertStringContainsString('Reports flow in automatically', $body);
        self::assertStringContainsString('24-48 hours', $body);

        // The misleading mailbox-alternative phrase is gone for this user.
        // We assert the literal string from the original NoRecord copy so a
        // regression that restores the old wording fails loudly.
        self::assertStringNotContainsString('Connect a mailbox if you prefer pulling them yourself', $body);
        // And the step's CTA is the DNS deep-link, not a "Connect mailbox" button.
        self::assertStringContainsString('Check DNS setup', $body);
    }

    #[Test]
    public function dismissedChecklistReSurfacesOnDmarcRegression(): void
    {
        // Once verified, then dismissed, then DMARC regresses → checklist
        // re-appears even though the dismissal column is set. Step 3
        // (first report) is left incomplete on purpose: the architect
        // spec's "fully-complete trumps everything" rule means a 3-of-3
        // team never sees the checklist, even on regression.
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);
        $fixtures = TestFixtures::fromContainer(self::getContainer());

        $persona = $fixtures->persona()
            ->emailPrefix('checklist-regress-'.substr(uniqid('', true), -6))
            ->withDomain('checklist-regress-'.substr(uniqid('', true), -6).'.example')
            ->build();
        assert(null !== $persona->domain);
        $persona->domain->dmarcVerifiedAt = new \DateTimeImmutable('-30 days');
        // firstReportAt intentionally null — see comment above.

        // Two consecutive failed checks after a previously-valid one →
        // DomainVerificationStatus reports consecutiveDmarcFailures = 2.
        $em->persist(new DnsCheckResult(
            id: Uuid::uuid7(),
            monitoredDomain: $persona->domain,
            type: DnsCheckType::Dmarc,
            checkedAt: new \DateTimeImmutable('-3 days'),
            rawRecord: 'v=DMARC1; p=none;',
            isValid: true,
            issues: [],
            details: [],
            previousRawRecord: null,
            hasChanged: false,
            isFirstCheck: false,
        ));
        $em->persist(new DnsCheckResult(
            id: Uuid::uuid7(),
            monitoredDomain: $persona->domain,
            type: DnsCheckType::Dmarc,
            checkedAt: new \DateTimeImmutable('-2 days'),
            rawRecord: null,
            isValid: false,
            issues: [],
            details: [],
            previousRawRecord: 'v=DMARC1; p=none;',
            hasChanged: false,
            isFirstCheck: false,
        ));
        $em->persist(new DnsCheckResult(
            id: Uuid::uuid7(),
            monitoredDomain: $persona->domain,
            type: DnsCheckType::Dmarc,
            checkedAt: new \DateTimeImmutable('-1 day'),
            rawRecord: null,
            isValid: false,
            issues: [],
            details: [],
            previousRawRecord: null,
            hasChanged: false,
            isFirstCheck: false,
        ));

        $persona->team->dismissSetupChecklist(new \DateTimeImmutable('-10 days'));
        $em->flush();

        $client->loginUser($persona->user);
        $client->request('GET', '/app');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Finish setting up Sendvery', $body);
    }
}
