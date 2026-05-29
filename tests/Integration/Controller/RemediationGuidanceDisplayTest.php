<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\AiInsight;
use App\Entity\DnsCheckResult;
use App\Entity\DomainHealthSnapshot;
use App\Entity\MonitoredDomain;
use App\Services\Ai\AiInsightCacheKey;
use App\Tests\Fixtures\Persona;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use App\Value\AiInsightType;
use App\Value\DnsCheckType;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

/**
 * The domain-health page is read-only for AI remediation: it shows guidance only
 * when it's already cached (generated async when a DNS check fails). It never
 * makes a synchronous Anthropic call during the page render.
 */
final class RemediationGuidanceDisplayTest extends WebTestCase
{
    public function testCachedGuidanceForAMissingRecordIsShown(): void
    {
        $client = self::createClient();
        [$persona, $domain] = $this->team('rem-cached');
        // No DMARC check at all → firstFailingType resolves to DMARC (null-result branch).
        $this->persistRemediation($domain, DnsCheckType::Dmarc, 'Publish a DMARC TXT record to start monitoring.');

        $client->loginUser($persona->user);
        $client->request('GET', '/app/domains/'.$domain->id->toString().'/health');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'AI guidance');
        self::assertSelectorTextContains('body', 'Publish a DMARC TXT record');
    }

    public function testAFailingRecordWithNoCachedGuidanceShowsNoCard(): void
    {
        $client = self::createClient();
        [$persona, $domain] = $this->team('rem-uncached');
        $this->persistCheck($domain, DnsCheckType::Dmarc, false);

        $client->loginUser($persona->user);
        $client->request('GET', '/app/domains/'.$domain->id->toString().'/health');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('AI guidance', (string) $client->getResponse()->getContent());
    }

    public function testAllRecordsValidShowsNoCard(): void
    {
        $client = self::createClient();
        [$persona, $domain] = $this->team('rem-valid');
        $this->persistCheck($domain, DnsCheckType::Dmarc, true);
        $this->persistCheck($domain, DnsCheckType::Spf, true);
        $this->persistCheck($domain, DnsCheckType::Dkim, true);

        $client->loginUser($persona->user);
        $client->request('GET', '/app/domains/'.$domain->id->toString().'/health');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('AI guidance', (string) $client->getResponse()->getContent());
    }

    public function testTheScoreTrendChartRendersWithMoreThanOneSnapshot(): void
    {
        $client = self::createClient();
        [$persona, $domain] = $this->team('rem-trend');
        $em = $this->getService(EntityManagerInterface::class);
        foreach ([70, 85] as $i => $score) {
            $em->persist(new DomainHealthSnapshot(
                id: Uuid::uuid7(),
                monitoredDomain: $domain,
                grade: 'B',
                score: $score,
                spfScore: $score,
                dkimScore: $score,
                dmarcScore: $score,
                mxScore: $score,
                blacklistScore: 100,
                checkedAt: new \DateTimeImmutable(sprintf('2026-05-0%d 00:00:00', $i + 1)),
            ));
        }
        $em->flush();

        $client->loginUser($persona->user);
        $client->request('GET', '/app/domains/'.$domain->id->toString().'/health');

        self::assertResponseIsSuccessful();
    }

    /**
     * @return array{Persona, MonitoredDomain}
     */
    private function team(string $prefix): array
    {
        $persona = TestFixtures::fromContainer(self::getContainer())
            ->persona()->emailPrefix($prefix)->teamName('AI '.$prefix)
            ->plan('personal_ai')->withDomain($prefix.'.example')->build();
        assert($persona->domain instanceof MonitoredDomain);

        return [$persona, $persona->domain];
    }

    private function persistCheck(MonitoredDomain $domain, DnsCheckType $type, bool $isValid): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $em->persist(new DnsCheckResult(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            type: $type,
            checkedAt: new \DateTimeImmutable(),
            rawRecord: $isValid ? 'v=valid' : null,
            isValid: $isValid,
            issues: [],
            details: [],
            previousRawRecord: null,
            hasChanged: false,
            isFirstCheck: true,
        ));
        $em->flush();
    }

    private function persistRemediation(MonitoredDomain $domain, DnsCheckType $type, string $instructions): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $em->persist(new AiInsight(
            id: Uuid::uuid7(),
            team: $domain->team,
            type: AiInsightType::Remediation,
            subjectId: $domain->id->toString(),
            cacheKey: AiInsightCacheKey::remediation($domain->id->toString(), $type->value),
            content: ['instructionsMarkdown' => $instructions, 'suggestedDnsRecords' => []],
            createdAt: new \DateTimeImmutable(),
        ));
        $em->flush();
    }
}
