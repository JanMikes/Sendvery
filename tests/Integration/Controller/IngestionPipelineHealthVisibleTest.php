<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\IngestionSourceStatus;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use App\Value\IngestionSource;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;

/**
 * An operator must be able to see when report intake last succeeded.
 *
 * Without it, "reports stopped arriving" is unanswerable from inside the
 * product: there is no way to tell a customer's broken rua= tag from our own
 * poller having died, and the only place the truth lived was a log file on the
 * box. Every ingestion question became an SSH session.
 *
 * The never-polled state gets its own assertion because it is the state of
 * every fresh deployment and of every local dev environment, where the central
 * inbox is not configured at all. Rendering that as a failure would be the
 * "unknown is not failure" rule broken on the very surface built to explain
 * unknowns.
 */
final class IngestionPipelineHealthVisibleTest extends WebTestCase
{
    #[Test]
    public function aRecentSuccessfulPollIsReportedAsHealthy(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $client->loginUser($fixtures->onboardedOwner()->user);

        $em = $this->getService(EntityManagerInterface::class);
        $status = new IngestionSourceStatus(Uuid::uuid7(), IngestionSource::CentralInbox);
        $status->recordSuccess($this->getService(\Psr\Clock\ClockInterface::class)->now()->modify('-3 minutes'));
        $em->persist($status);
        $em->flush();

        $client->request('GET', '/app/mailboxes');

        self::assertResponseIsSuccessful();

        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString(
            'Report intake',
            $content,
            'The ingestion page must name our own report intake as something with a state, or an operator has nowhere to look.',
        );
        self::assertStringContainsStringIgnoringCase(
            'running normally',
            $content,
            'A poll that succeeded three minutes ago is healthy and must say so plainly.',
        );
    }

    #[Test]
    public function aPipelineThatHasNeverPolledSaysSoWithoutClaimingItIsBroken(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $client->loginUser($fixtures->onboardedOwner()->user);

        // No IngestionSourceStatus row at all — a fresh deployment.
        $client->request('GET', '/app/mailboxes');

        self::assertResponseIsSuccessful();

        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsStringIgnoringCase(
            'not checked yet',
            $content,
            'Never having polled is an absence of measurement, not a fault, and the page must say which it is.',
        );
        self::assertStringNotContainsString(
            'text-error',
            $this->intakeSection($content),
            'An unmeasured pipeline must not wear the error tone. On a fresh install every operator would meet a red panel describing a system that has simply not run yet.',
        );
    }

    #[Test]
    public function aStalePipelineIsSurfacedAsNeedingAttention(): void
    {
        $client = self::createClient();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $client->loginUser($fixtures->onboardedOwner()->user);

        $em = $this->getService(EntityManagerInterface::class);
        $status = new IngestionSourceStatus(Uuid::uuid7(), IngestionSource::CentralInbox);
        // Cron runs every 5 minutes, so six hours of silence is ~72 missed runs.
        $status->recordSuccess($this->getService(\Psr\Clock\ClockInterface::class)->now()->modify('-6 hours'));
        $em->persist($status);
        $em->flush();

        $client->request('GET', '/app/mailboxes');

        self::assertResponseIsSuccessful();
        self::assertStringContainsStringIgnoringCase(
            'has not completed',
            (string) $client->getResponse()->getContent(),
            'A pipeline that last succeeded six hours ago is genuinely stuck and must be distinguishable from one that never ran.',
        );
    }

    /**
     * Narrows the tone assertion to the intake panel. The page also lists
     * mailboxes and a DNS matrix, either of which may legitimately be red for
     * reasons that have nothing to do with our own pipeline.
     */
    private function intakeSection(string $content): string
    {
        $start = strpos($content, 'data-testid="ingestion-intake-health"');

        self::assertNotFalse($start, 'The intake panel must be findable so its tone can be asserted independently of the rest of the page.');

        return substr($content, $start, 1200);
    }
}
