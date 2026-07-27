<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageHandler;

use App\Message\SendWeeklyDigest;
use App\MessageHandler\SendWeeklyDigestHandler;
use App\Services\Ai\AiInsightsService;
use App\Services\Ai\Input\DnsCheckFailure;
use App\Services\Ai\Result\AnomalyExplanationResult;
use App\Services\Ai\Result\OnDemandExplanationResult;
use App\Services\Ai\Result\RemediationResult;
use App\Services\Ai\Result\SenderLabelResult;
use App\Services\Ai\Result\WeeklyDigestResult;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\IntegrationTestCase;
use App\Value\SubscriptionPlan;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\UuidInterface;

/**
 * The AI narration is additive garnish on a digest that is already complete
 * without it, so an upstream failure must never cost the team its email.
 */
final class WeeklyDigestAiFailureTest extends IntegrationTestCase
{
    #[Test]
    public function aFailingAiCallStillDeliversTheDigestWithoutTheAiSection(): void
    {
        $persona = TestFixtures::fromContainer(self::getContainer())
            ->persona()->emailPrefix('digest-ai-fail')->teamName('Digest AI failure')
            ->plan(SubscriptionPlan::PersonalAi->value)->withDomain('digest-ai-fail.example')->build();

        self::getContainer()->set(AiInsightsService::class, new ThrowingAiInsightsService());

        $before = count(self::getMailerMessages());

        $this->getService(SendWeeklyDigestHandler::class)(new SendWeeklyDigest($persona->team->id));

        self::assertGreaterThan(
            $before,
            count(self::getMailerMessages()),
            'An AI-plan team must still receive its weekly digest when the AI provider fails — '
            .'aborting the send would leave them worse off than a team with no AI at all.',
        );
    }
}

/**
 * Stands in for a provider outage / expired key / rate limit.
 */
final class ThrowingAiInsightsService implements AiInsightsService
{
    public function generateWeeklyDigest(UuidInterface $teamId): WeeklyDigestResult
    {
        throw new \RuntimeException('Simulated AI provider failure.');
    }

    public function explainAnomaly(UuidInterface $reportId, UuidInterface $teamId): AnomalyExplanationResult
    {
        throw new \RuntimeException('Not exercised by this test.');
    }

    public function explainReport(UuidInterface $reportId, UuidInterface $teamId): OnDemandExplanationResult
    {
        throw new \RuntimeException('Not exercised by this test.');
    }

    public function generateRemediationGuidance(UuidInterface $domainId, DnsCheckFailure $failure): RemediationResult
    {
        throw new \RuntimeException('Not exercised by this test.');
    }

    public function labelSender(string $ip, string $domain): SenderLabelResult
    {
        throw new \RuntimeException('Not exercised by this test.');
    }
}
