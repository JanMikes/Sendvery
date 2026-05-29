<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\DnsCheckResult;
use App\Entity\MonitoredDomain;
use App\Events\DnsCheckCompleted;
use App\Message\GenerateRemediationInsight;
use App\MessageHandler\GenerateRemediationInsightHandler;
use App\MessageHandler\GenerateRemediationInsightWhenDnsCheckFails;
use App\Tests\Fixtures\Persona;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\IntegrationTestCase;
use App\Value\DnsCheckType;
use App\Value\SubscriptionPlan;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class GenerateRemediationInsightTest extends IntegrationTestCase
{
    public function testAFailingDnsCheckQueuesRemediationGeneration(): void
    {
        $this->detector()(new DnsCheckCompleted(
            dnsCheckResultId: Uuid::uuid7(),
            domainId: Uuid::uuid7(),
            teamId: Uuid::uuid7(),
            type: DnsCheckType::Spf,
            hasChanged: false,
            isValid: false,
            rawRecord: null,
            previousRawRecord: null,
        ));

        $sent = $this->asyncTransport()->getSent();
        self::assertCount(1, $sent);
        self::assertInstanceOf(GenerateRemediationInsight::class, $sent[0]->getMessage());
    }

    public function testAValidCheckQueuesNothing(): void
    {
        $this->detector()(new DnsCheckCompleted(
            dnsCheckResultId: Uuid::uuid7(),
            domainId: Uuid::uuid7(),
            teamId: Uuid::uuid7(),
            type: DnsCheckType::Spf,
            hasChanged: false,
            isValid: true,
            rawRecord: 'v=spf1 -all',
            previousRawRecord: null,
        ));

        self::assertCount(0, $this->asyncTransport()->getSent());
    }

    public function testAFailingMxCheckQueuesNothingBecauseMxIsOutOfScope(): void
    {
        $this->detector()(new DnsCheckCompleted(
            dnsCheckResultId: Uuid::uuid7(),
            domainId: Uuid::uuid7(),
            teamId: Uuid::uuid7(),
            type: DnsCheckType::Mx,
            hasChanged: false,
            isValid: false,
            rawRecord: null,
            previousRawRecord: null,
        ));

        self::assertCount(0, $this->asyncTransport()->getSent());
    }

    public function testWorkerGeneratesAndCachesGuidanceForAnAiTeam(): void
    {
        [$persona, $domain, $check] = $this->seed('rem-worker-ai', SubscriptionPlan::PersonalAi);

        $this->worker()(new GenerateRemediationInsight($domain->id, $persona->team->id, DnsCheckType::Dmarc, $check->id));

        self::assertSame(1, $this->countRemediation());
    }

    public function testWorkerSkipsNonAiTeams(): void
    {
        [$persona, $domain, $check] = $this->seed('rem-worker-free', SubscriptionPlan::Personal);

        $this->worker()(new GenerateRemediationInsight($domain->id, $persona->team->id, DnsCheckType::Dmarc, $check->id));

        self::assertSame(0, $this->countRemediation());
    }

    private function detector(): GenerateRemediationInsightWhenDnsCheckFails
    {
        return $this->getService(GenerateRemediationInsightWhenDnsCheckFails::class);
    }

    private function worker(): GenerateRemediationInsightHandler
    {
        return $this->getService(GenerateRemediationInsightHandler::class);
    }

    private function asyncTransport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        assert($transport instanceof InMemoryTransport);

        return $transport;
    }

    private function countRemediation(): int
    {
        return (int) $this->getService(EntityManagerInterface::class)->getConnection()
            ->executeQuery("SELECT COUNT(*) FROM ai_insight WHERE type = 'remediation'")
            ->fetchOne();
    }

    /**
     * @return array{Persona, MonitoredDomain, DnsCheckResult}
     */
    private function seed(string $prefix, SubscriptionPlan $plan): array
    {
        $em = $this->getService(EntityManagerInterface::class);
        $persona = TestFixtures::fromContainer(self::getContainer())
            ->persona()->emailPrefix($prefix)->teamName('AI '.$prefix)
            ->plan($plan->value)->withDomain($prefix.'.example')->build();
        assert($persona->domain instanceof MonitoredDomain);

        $check = new DnsCheckResult(
            id: Uuid::uuid7(),
            monitoredDomain: $persona->domain,
            type: DnsCheckType::Dmarc,
            checkedAt: new \DateTimeImmutable(),
            rawRecord: null,
            isValid: false,
            issues: [['severity' => 'error', 'message' => 'No DMARC record found.']],
            details: [],
            previousRawRecord: null,
            hasChanged: false,
            isFirstCheck: true,
        );
        $em->persist($check);
        $em->flush();

        return [$persona, $persona->domain, $check];
    }
}
