<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\DnsCheckResult;
use App\Entity\DomainHealthSnapshot;
use App\Entity\MonitoredDomain;
use App\Entity\Team;
use App\Tests\Fixtures\Persona;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use App\Value\DnsCheckType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The `/app` "Needs your attention" list, end to end through the controller.
 *
 * The load-bearing test here is the last one: the reason the dashboard gives for
 * a domain has to be the reason that domain's own page gives, character for
 * character. Two surfaces describing the same broken record in two different
 * ways is how a user ends up unsure which one to believe.
 */
final class DashboardAttentionListTest extends WebTestCase
{
    private const string DMARC_RECORD_POINTING_AT_SENDVERY = 'v=DMARC1; p=none; rua=mailto:reports@sendvery.com;';

    #[Test]
    public function eachListedDomainIsNamedWithItsReasonAndADirectLinkToTheFix(): void
    {
        [$client, $domain] = $this->clientWithDomainMissingSpf();

        $client->request('GET', '/app');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('Needs your attention', $body);
        self::assertStringContainsString($domain->domain, $body);
        self::assertStringContainsString('Action needed — SPF', $body);
        self::assertStringContainsString('SPF record not detected', $body);
        self::assertStringContainsString(
            '/app/domains/'.$domain->id->toString().'/health#health-spf',
            $body,
            'The row CTA must deep-link the SPF section of that domain\'s setup surface.',
        );
    }

    #[Test]
    public function aTeamWhoseDomainsAreAllHealthyGetsNoAttentionListAtAll(): void
    {
        // An empty panel titled "Needs your attention" reads as broken. The focus
        // card's "All domains healthy" headline carries the all-clear instead.
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $em = $this->getService(EntityManagerInterface::class);

        $persona = $fixtures->persona()->withoutDomain()->build();
        $this->healthyDomain($em, $persona->team);
        $em->flush();

        $client->loginUser($persona->user);
        $client->request('GET', '/app');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();

        self::assertStringNotContainsString('Needs your attention', $body);
        self::assertStringContainsString('All domains healthy', $body);
    }

    #[Test]
    public function theDashboardAndTheDomainPageDescribeTheSameProblemIdentically(): void
    {
        [$client, $domain] = $this->clientWithDomainMissingSpf();

        $client->request('GET', '/app');
        self::assertResponseIsSuccessful();
        $overview = (string) $client->getResponse()->getContent();

        $client->request('GET', '/app/domains/'.$domain->id->toString().'/health');
        self::assertResponseIsSuccessful();
        $domainPage = (string) $client->getResponse()->getContent();

        foreach (['SPF record not detected'] as $reason) {
            self::assertStringContainsString($reason, $overview, 'The dashboard must state the reason.');
            self::assertStringContainsString(
                $reason,
                $domainPage,
                'And the domain page must state it in exactly the same words — the dashboard reads its copy from the same resolver rather than writing its own.',
            );
        }
    }

    /**
     * @return array{KernelBrowser, MonitoredDomain}
     */
    private function clientWithDomainMissingSpf(): array
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $em = $this->getService(EntityManagerInterface::class);

        $persona = $fixtures->persona()->withoutDomain()->build();
        $domain = $this->healthyDomain($em, $persona->team);
        $domain->spfVerifiedAt = null;
        $this->check($em, $domain, DnsCheckType::Spf, '-10 minutes', rawRecord: null, isValid: false);
        $em->flush();

        $client->loginUser($persona->user);

        return [$client, $domain];
    }

    private function healthyDomain(EntityManagerInterface $em, Team $team, ?Persona $persona = null): MonitoredDomain
    {
        $id = Uuid::uuid7();
        $domain = new MonitoredDomain(
            id: $id,
            team: $team,
            domain: 'attention-'.$id->toString().'.example',
            createdAt: new \DateTimeImmutable('-10 days'),
            spfVerifiedAt: new \DateTimeImmutable('-9 days'),
            dkimVerifiedAt: new \DateTimeImmutable('-9 days'),
            dmarcVerifiedAt: new \DateTimeImmutable('-9 days'),
        );
        $domain->popEvents();
        $em->persist($domain);

        $em->persist(new DomainHealthSnapshot(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            grade: 'A',
            score: 95,
            spfScore: 100,
            dkimScore: 100,
            dmarcScore: 100,
            mxScore: 95,
            blacklistScore: 100,
            checkedAt: new \DateTimeImmutable('-1 hour'),
        ));

        foreach ([DnsCheckType::Spf, DnsCheckType::Dkim, DnsCheckType::Mx] as $type) {
            $this->check($em, $domain, $type, '-2 hours', rawRecord: 'ok', isValid: true);
        }
        $this->check($em, $domain, DnsCheckType::Dmarc, '-2 hours', rawRecord: self::DMARC_RECORD_POINTING_AT_SENDVERY, isValid: true);

        return $domain;
    }

    private function check(
        EntityManagerInterface $em,
        MonitoredDomain $domain,
        DnsCheckType $type,
        string $checkedAt,
        ?string $rawRecord,
        bool $isValid,
    ): void {
        $em->persist(new DnsCheckResult(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            type: $type,
            checkedAt: new \DateTimeImmutable($checkedAt),
            rawRecord: $rawRecord,
            isValid: $isValid,
            issues: [],
            details: [],
            previousRawRecord: null,
            hasChanged: false,
            isFirstCheck: false,
        ));
    }
}
