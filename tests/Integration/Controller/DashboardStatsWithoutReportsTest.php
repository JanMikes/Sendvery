<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * DMARC report rows only exist once `sendvery:reports:poll-inbox` has ingested
 * something, so a team that has just published its DMARC record has no messages
 * to grade. The `/app` "DMARC Pass Rate" stat card must say so, rather than
 * reporting a red 0.0% — which claims every message the team sent failed
 * authentication.
 *
 * The domain cards on the same page already got this right ("Waiting for first
 * report"), so the old card also contradicted its own page.
 */
final class DashboardStatsWithoutReportsTest extends WebTestCase
{
    #[Test]
    public function dashboardPassRateCardSaysItIsWaitingInsteadOfReportingZeroPercent(): void
    {
        $client = self::createClient();
        $persona = TestFixtures::fromContainer(self::getContainer())->persona()->build();
        $client->loginUser($persona->user);

        $client->request('GET', '/app');

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();

        self::assertStringNotContainsString(
            '0.0%',
            $content,
            'A team with no DMARC reports has no pass rate. Showing 0.0% claims every message failed authentication.',
        );
        self::assertStringContainsString(
            'Waiting for first report',
            $content,
            'The pass-rate card must state what the user is waiting for, not just blank the number.',
        );
    }
}
