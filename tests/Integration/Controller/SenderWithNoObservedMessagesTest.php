<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\KnownSender;
use App\Results\DomainDetailResult;
use App\Results\SenderInventoryResult;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\WebTestCase;
use App\Value\SenderReviewState;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Twig\Environment;

/**
 * A `known_sender` row whose observed volume is zero.
 *
 * Reachable, though narrowly: `DmarcXmlParser` defaults a missing `<count>`
 * element to 0 (`(int) (string) ($row->count ?? '0')`), and
 * `SenderDiscovery::aggregateBySourceIp()` groups by source IP with no
 * `HAVING SUM(rec.count) > 0` — so a reporter that describes a record carrying
 * no messages creates a sender row with `total_messages = 0`, and
 * `SenderDiscovery::passRate(0, 0)` stores `0.0`.
 *
 * The Sender Inventory table and the exported PDF then printed that as a red
 * 0.0% — "every message from this host failed" about a host we have seen no
 * messages from. Both surfaces carried the last two hand-rolled pass-rate
 * ternaries in the app, neither with a null branch.
 */
final class SenderWithNoObservedMessagesTest extends WebTestCase
{
    #[Test]
    public function theInventoryShowsNoRateRatherThanAFailingOneForAHostWeHaveSeenNoMailFrom(): void
    {
        $data = $this->clientWithAZeroVolumeSender();

        $data['client']->request('GET', '/app/domains/'.$data['domainId'].'/senders');

        self::assertResponseIsSuccessful();
        $row = $this->rowFor($data['client'], '203.0.113.9');

        self::assertStringNotContainsString('0.0%', $row, 'Zero pass rate is a measurement; zero messages is the absence of one.');
        self::assertStringContainsString('—', $row);
        self::assertStringNotContainsString('text-error', $row);
    }

    #[Test]
    public function aSenderWithRealVolumeStillShowsItsMeasuredRate(): void
    {
        // The guard against over-correcting.
        $data = $this->clientWithAZeroVolumeSender();

        $data['client']->request('GET', '/app/domains/'.$data['domainId'].'/senders');

        $row = $this->rowFor($data['client'], '198.51.100.7');
        self::assertStringContainsString('42.5%', $row);
    }

    #[Test]
    public function theExportedReportDoesNotPrintAFailingGradeForAHostWithNoMessages(): void
    {
        // An export leaves the product and gets forwarded to people who cannot
        // ask us what the number meant, so a fabricated failing grade is worse
        // here than on screen, not better.
        //
        // The template is rendered directly rather than through the route: the
        // route is plan-gated and its output is a binary PDF, and neither the
        // entitlement nor the PDF encoder is what this behaviour is about. The
        // real template is imported, not a copy of it.
        self::createClient();
        $twig = self::getContainer()->get(Environment::class);
        assert($twig instanceof Environment);

        $html = $twig->render('pdf/domain_report.html.twig', [
            'domain' => new DomainDetailResult(
                domainId: Uuid::uuid7()->toString(),
                domainName: 'zero-volume.example',
                dmarcPolicy: 'none',
                spfVerifiedAt: null,
                dkimVerifiedAt: null,
                dmarcVerifiedAt: null,
                firstReportAt: null,
                createdAt: '2026-07-01 00:00:00',
                totalReports: 0,
                totalMessages: 0,
                passRate: null,
                uniqueSenders: 1,
                dkimSelector: null,
            ),
            'reportData' => null,
            'healthSnapshot' => null,
            'senders' => [
                new SenderInventoryResult(
                    id: Uuid::uuid7()->toString(),
                    sourceIp: '203.0.113.9',
                    hostname: null,
                    organization: null,
                    label: null,
                    isAuthorized: false,
                    firstSeenAt: '2026-07-25 00:00:00',
                    lastSeenAt: '2026-07-27 00:00:00',
                    totalMessages: 0,
                    passRate: null,
                    updatedAt: null,
                    notes: null,
                    updatedByUserEmail: null,
                    reviewState: SenderReviewState::NeedsReview,
                ),
            ],
            'generatedAt' => new \DateTimeImmutable('2026-07-28 00:00:00'),
        ]);

        self::assertStringContainsString('203.0.113.9', $html);
        self::assertStringNotContainsString('0.0%', $html);
        self::assertStringContainsString('&mdash;', $html);
    }

    private function rowFor(KernelBrowser $client, string $sourceIp): string
    {
        $rows = $client->getCrawler()->filter(sprintf('tr:contains("%s")', $sourceIp));
        self::assertGreaterThan(0, $rows->count(), sprintf('Expected an inventory row for %s', $sourceIp));

        return $rows->first()->html();
    }

    /**
     * @return array{client: KernelBrowser, domainId: string}
     */
    private function clientWithAZeroVolumeSender(): array
    {
        $client = self::createClient();
        $em = $this->getService(EntityManagerInterface::class);
        $fixtures = TestFixtures::fromContainer(self::getContainer());

        $suffix = substr(uniqid('', true), -6);
        $persona = $fixtures->persona()
            ->emailPrefix('zero-volume-'.$suffix)
            ->withDomain('zero-volume-'.$suffix.'.example')
            ->build();
        assert(null !== $persona->domain);

        $em->persist(new KnownSender(
            id: Uuid::uuid7(),
            monitoredDomain: $persona->domain,
            sourceIp: '203.0.113.9',
            firstSeenAt: new \DateTimeImmutable('-3 days'),
            lastSeenAt: new \DateTimeImmutable('-1 day'),
            totalMessages: 0,
            passRate: 0.0,
        ));
        $em->persist(new KnownSender(
            id: Uuid::uuid7(),
            monitoredDomain: $persona->domain,
            sourceIp: '198.51.100.7',
            firstSeenAt: new \DateTimeImmutable('-3 days'),
            lastSeenAt: new \DateTimeImmutable('-1 day'),
            totalMessages: 200,
            passRate: 42.5,
        ));

        $em->flush();
        $client->loginUser($persona->user);

        return [
            'client' => $client,
            'domainId' => $persona->domain->id->toString(),
        ];
    }
}
