<?php

declare(strict_types=1);

namespace App\Tests\Integration\Query;

use App\Query\GetSendersAwaitingReview;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\IntegrationTestCase;
use App\Value\SenderReviewState;
use PHPUnit\Framework\Attributes\Test;

final class GetSendersAwaitingReviewTest extends IntegrationTestCase
{
    #[Test]
    public function reportsCountVolumeAndTheBiggestUnreviewedSenderPerDomain(): void
    {
        self::bootKernel();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $query = $this->getService(GetSendersAwaitingReview::class);

        $persona = $fixtures->persona()
            ->emailPrefix('awaiting-review')
            ->withKnownSender('77.75.78.89', totalMessages: 640, organization: 'Seznam')
            ->withKnownSender('77.75.78.90', totalMessages: 60, hostname: 'mxb-1-910.seznam.cz')
            ->withKnownSender('203.0.113.9', totalMessages: 300, reviewState: SenderReviewState::Authorized)
            ->build();
        assert(null !== $persona->domain);

        $rows = $query->forTeam($persona->team->id->toString());

        self::assertCount(1, $rows);
        self::assertSame($persona->domain->domain, $rows[0]->domainName);
        self::assertSame(2, $rows[0]->needsReviewCount, 'The authorized sender is a settled decision.');
        self::assertSame(700, $rows[0]->needsReviewMessages);
        self::assertSame(640, $rows[0]->largestSenderMessages);
        self::assertSame(1000, $rows[0]->domainMessages, 'The share denominator is all mail, reviewed or not.');
        self::assertSame(['Seznam', 'mxb-1-910.seznam.cz'], $rows[0]->topSenderNames);
    }

    /**
     * A provider sending from several outbound machines is one service the
     * reader recognises, not five. Listing "Seznam, Seznam, Seznam" made the
     * email look broken while telling the reader nothing extra.
     */
    #[Test]
    public function severalAddressesBelongingToOneProviderAreNamedOnce(): void
    {
        self::bootKernel();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $query = $this->getService(GetSendersAwaitingReview::class);

        $persona = $fixtures->persona()
            ->emailPrefix('awaiting-grouped')
            ->withKnownSender('77.75.78.89', totalMessages: 300, organization: 'Seznam')
            ->withKnownSender('77.75.78.90', totalMessages: 200, organization: 'Seznam')
            ->withKnownSender('77.75.78.91', totalMessages: 100, organization: 'Seznam')
            ->build();

        $rows = $query->forTeam($persona->team->id->toString());

        self::assertCount(1, $rows);
        self::assertSame(3, $rows[0]->needsReviewCount, 'The headline count is still per address.');
        self::assertSame(['Seznam'], $rows[0]->topSenderNames);
        self::assertSame(1, $rows[0]->distinctNameCount);
        self::assertFalse($rows[0]->hasMoreThanNamed(), 'Nothing is hidden — the one name covers all three addresses.');
    }

    #[Test]
    public function aDomainWhereEverySenderIsDecidedIsNotReportedAtAll(): void
    {
        self::bootKernel();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $query = $this->getService(GetSendersAwaitingReview::class);

        $persona = $fixtures->persona()
            ->emailPrefix('awaiting-none')
            ->withKnownSender('203.0.113.1', reviewState: SenderReviewState::Authorized)
            ->withKnownSender('203.0.113.2', reviewState: SenderReviewState::NotAuthorized)
            ->build();

        self::assertSame([], $query->forTeam($persona->team->id->toString()));
    }

    #[Test]
    public function aTeamWithNoSendersAtAllIsNotReported(): void
    {
        self::bootKernel();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $query = $this->getService(GetSendersAwaitingReview::class);

        $persona = $fixtures->persona()->emailPrefix('awaiting-empty')->build();

        self::assertSame([], $query->forTeam($persona->team->id->toString()));
    }

    /**
     * The nightly prefilter: only teams with something outstanding should cost
     * the per-domain aggregate.
     */
    #[Test]
    public function theTeamPrefilterFindsTeamsWithUnreviewedSendersAndSkipsSettledOnes(): void
    {
        self::bootKernel();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $query = $this->getService(GetSendersAwaitingReview::class);

        $waiting = $fixtures->persona()
            ->emailPrefix('prefilter-waiting')
            ->withKnownSender('203.0.113.3')
            ->build();
        $settled = $fixtures->persona()
            ->emailPrefix('prefilter-settled')
            ->withKnownSender('203.0.113.4', reviewState: SenderReviewState::Authorized)
            ->build();

        $teamIds = $query->teamIdsWithUnreviewedSenders();

        self::assertContains($waiting->team->id->toString(), $teamIds);
        self::assertNotContains($settled->team->id->toString(), $teamIds);
    }

    #[Test]
    public function oneTeamsUnreviewedSendersNeverLeakIntoAnothersReport(): void
    {
        self::bootKernel();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $query = $this->getService(GetSendersAwaitingReview::class);

        $fixtures->persona()->emailPrefix('leak-source')->withKnownSender('198.51.100.5')->build();
        $other = $fixtures->persona()->emailPrefix('leak-target')->build();

        self::assertSame([], $query->forTeam($other->team->id->toString()));
    }

    #[Test]
    public function heaviestDomainComesFirstSoTheEmailLeadsWithWhatMatters(): void
    {
        self::bootKernel();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $query = $this->getService(GetSendersAwaitingReview::class);

        $persona = $fixtures->persona()
            ->emailPrefix('awaiting-order-light')
            ->withKnownSender('203.0.113.5', totalMessages: 10)
            ->build();

        $heavyDomain = $fixtures->addExtraDomain($persona->team, 'heavy-'.$persona->team->id->toString());
        $fixtures->addKnownSender($heavyDomain, '203.0.113.6', totalMessages: 900);

        $rows = $query->forTeam($persona->team->id->toString());

        self::assertCount(2, $rows);
        self::assertSame($heavyDomain->domain, $rows[0]->domainName);
    }
}
