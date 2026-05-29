<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Entity\DnsCheckResult;
use App\Entity\MonitoredDomain;
use App\Message\SendWeeklyDigest;
use App\MessageHandler\SendWeeklyDigestHandler;
use App\Tests\Fixtures\Persona;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\IntegrationTestCase;
use App\Value\DnsCheckType;
use App\Value\SubscriptionPlan;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;

final class WeeklyDigestAiSummaryTest extends IntegrationTestCase
{
    #[Test]
    public function aiPlanTeamsGetAnAiSummaryGeneratedAndCachedForTheWeek(): void
    {
        $persona = $this->digestTeam('digest-ai', SubscriptionPlan::PersonalAi);
        self::assertNotNull($persona->domain);
        // A persistently-broken record surfaces in the digest facts (exercises the
        // broken-DNS projection in the summary).
        $this->persistBrokenDnsCheck($persona->domain);

        $this->getService(SendWeeklyDigestHandler::class)(new SendWeeklyDigest($persona->team->id));

        self::assertSame(1, $this->countWeeklyDigestInsights());
    }

    #[Test]
    public function nonAiTeamsGetTheUnchangedDigestWithNoAiSummary(): void
    {
        $persona = $this->digestTeam('digest-noai', SubscriptionPlan::Personal);

        $this->getService(SendWeeklyDigestHandler::class)(new SendWeeklyDigest($persona->team->id));

        self::assertSame(0, $this->countWeeklyDigestInsights());
    }

    private function digestTeam(string $prefix, SubscriptionPlan $plan): Persona
    {
        // Persona users have email_digest_enabled = true by default, so they are
        // digest recipients and the handler proceeds past the recipients check.
        return TestFixtures::fromContainer(self::getContainer())
            ->persona()->emailPrefix($prefix)->teamName('Digest '.$prefix)
            ->plan($plan->value)->withDomain($prefix.'.example')->build();
    }

    private function persistBrokenDnsCheck(MonitoredDomain $domain): void
    {
        $em = $this->getService(EntityManagerInterface::class);
        $em->persist(new DnsCheckResult(
            id: Uuid::uuid7(),
            monitoredDomain: $domain,
            type: DnsCheckType::Dmarc,
            checkedAt: new \DateTimeImmutable(),
            rawRecord: null,
            isValid: false,
            issues: [['severity' => 'error', 'message' => 'No DMARC record found.']],
            details: [],
            previousRawRecord: null,
            hasChanged: false,
            isFirstCheck: true,
        ));
        $em->flush();
    }

    private function countWeeklyDigestInsights(): int
    {
        return (int) $this->getService(EntityManagerInterface::class)->getConnection()
            ->executeQuery("SELECT COUNT(*) FROM ai_insight WHERE type = 'weekly_digest'")
            ->fetchOne();
    }
}
