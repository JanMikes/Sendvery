<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\DnsCheckResult;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Entity\TeamMembership;
use App\Entity\User;
use App\Tests\WebTestCase;
use App\Value\DnsCheckType;
use App\Value\TeamRole;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * End-to-end coverage for TASK-095's per-category DNS recommendation cards
 * on `/app/domains/{id}/health`. Each branch seeds the matching DnsCheckResult
 * shape, renders the page, and asserts the right card variant surfaces.
 *
 * The DMARC branch isn't tested here — it's already covered by the existing
 * dashboard_domain_health tests that render `<twig:DnsRecordInstruction>` for
 * the `_dmarc.` host above the score cards.
 */
final class DnsRecordRecommendationsTest extends WebTestCase
{
    /**
     * @return array{client: KernelBrowser, em: EntityManagerInterface, domain: MonitoredDomain}
     */
    private function bootClient(): array
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        assert($em instanceof EntityManagerInterface);

        $user = new User(
            id: Uuid::uuid7(),
            email: 'dns-rec-'.Uuid::uuid7()->toString().'@example.com',
            createdAt: new \DateTimeImmutable(),
            onboardingCompletedAt: new \DateTimeImmutable(),
        );
        $user->popEvents();
        $em->persist($user);

        $team = new Team(
            id: Uuid::uuid7(),
            name: 'DNS Rec Team',
            slug: 'dns-rec-'.Uuid::uuid7()->toString(),
            createdAt: new \DateTimeImmutable(),
        );
        $team->popEvents();
        $em->persist($team);

        $em->persist(new TeamMembership(
            id: Uuid::uuid7(),
            user: $user,
            team: $team,
            role: TeamRole::Owner,
            joinedAt: new \DateTimeImmutable(),
        ));

        $domain = new MonitoredDomain(
            id: Uuid::uuid7(),
            team: $team,
            domain: 'dnsrec-'.Uuid::uuid7()->toString().'.example',
            createdAt: new \DateTimeImmutable(),
        );
        $domain->popEvents();
        $em->persist($domain);

        $em->flush();
        $client->loginUser($user);

        return ['client' => $client, 'em' => $em, 'domain' => $domain];
    }

    /**
     * @param array<string, mixed> $details
     */
    private function persistCheck(
        EntityManagerInterface $em,
        MonitoredDomain $domain,
        DnsCheckType $type,
        ?string $rawRecord,
        bool $isValid,
        array $details = [],
    ): DnsCheckResult {
        $check = new DnsCheckResult(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            type: $type,
            checkedAt: new \DateTimeImmutable(),
            rawRecord: $rawRecord,
            isValid: $isValid,
            issues: [],
            details: $details,
            previousRawRecord: null,
            hasChanged: false,
        );
        $check->popEvents();
        $em->persist($check);
        $em->flush();

        return $check;
    }

    #[Test]
    public function spfMissingRendersDnsRecordInstructionCardWithStrictBaseline(): void
    {
        $boot = $this->bootClient();
        // Seed DMARC + DKIM as "fine" so only the SPF card surfaces — keeps
        // the assertions focused on the branch under test.
        $this->persistCheck($boot['em'], $boot['domain'], DnsCheckType::Dmarc, 'v=DMARC1; p=none; rua=mailto:reports@sendvery.test', true);
        $this->persistCheck($boot['em'], $boot['domain'], DnsCheckType::Dkim, 'v=DKIM1; k=rsa; p=...', true);

        $crawler = $boot['client']->request('GET', '/app/domains/'.$boot['domain']->id->toString().'/health');

        self::assertResponseIsSuccessful();
        $bucket = $crawler->filter('[data-testid="dns-record-recommendations"]');
        self::assertGreaterThan(0, $bucket->count());
        self::assertStringContainsString('v=spf1 -all', $bucket->text());
    }

    #[Test]
    public function spfOverLookupLimitRendersHowToCardWithoutCopyableValue(): void
    {
        $boot = $this->bootClient();
        $this->persistCheck($boot['em'], $boot['domain'], DnsCheckType::Dmarc, 'v=DMARC1; p=none; rua=mailto:reports@sendvery.test', true);
        $this->persistCheck($boot['em'], $boot['domain'], DnsCheckType::Dkim, 'v=DKIM1; k=rsa; p=...', true);
        $this->persistCheck(
            $boot['em'],
            $boot['domain'],
            DnsCheckType::Spf,
            'v=spf1 include:_spf.google.com include:spf.mailgun.org -all',
            false,
            ['lookup_count' => 12, 'includes' => ['_spf.google.com']],
        );

        $crawler = $boot['client']->request('GET', '/app/domains/'.$boot['domain']->id->toString().'/health');

        self::assertResponseIsSuccessful();
        $howTo = $crawler->filter('[data-testid="dns-record-howto"]');
        self::assertGreaterThan(0, $howTo->count(), 'How-to card must render for the trim-this branch.');
        self::assertStringContainsString('12 lookups', $howTo->text());
    }

    #[Test]
    public function dkimMissingRendersHowToCardWithKbLink(): void
    {
        $boot = $this->bootClient();
        $this->persistCheck($boot['em'], $boot['domain'], DnsCheckType::Dmarc, 'v=DMARC1; p=none; rua=mailto:reports@sendvery.test', true);
        $this->persistCheck($boot['em'], $boot['domain'], DnsCheckType::Spf, 'v=spf1 -all', true);

        $crawler = $boot['client']->request('GET', '/app/domains/'.$boot['domain']->id->toString().'/health');

        self::assertResponseIsSuccessful();
        $howTo = $crawler->filter('[data-testid="dns-record-howto"][data-record-type="txt"]');
        self::assertGreaterThan(0, $howTo->count());
        $kb = $crawler->filter('[data-testid="dns-record-howto-kb-link"]');
        self::assertGreaterThan(0, $kb->count(), 'How-to card must surface a KB link.');
        self::assertStringContainsString('/learn/what-is-dkim', (string) $kb->attr('href'));
    }

    #[Test]
    public function healthyDomainRendersNoRecommendationBucket(): void
    {
        $boot = $this->bootClient();
        $this->persistCheck($boot['em'], $boot['domain'], DnsCheckType::Spf, 'v=spf1 -all', true);
        $this->persistCheck($boot['em'], $boot['domain'], DnsCheckType::Dkim, 'v=DKIM1; k=rsa; p=...', true);
        $this->persistCheck($boot['em'], $boot['domain'], DnsCheckType::Dmarc, 'v=DMARC1; p=none; rua=mailto:reports@sendvery.test', true);
        $this->persistCheck($boot['em'], $boot['domain'], DnsCheckType::Mx, '10 mx.example.com', true);

        $crawler = $boot['client']->request('GET', '/app/domains/'.$boot['domain']->id->toString().'/health');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-testid="dns-record-recommendations"]'));
    }
}
