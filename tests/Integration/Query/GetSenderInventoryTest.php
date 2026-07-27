<?php

declare(strict_types=1);

namespace App\Tests\Integration\Query;

use App\Query\GetSenderInventory;
use App\Tests\Fixtures\TestFixtures;
use App\Tests\IntegrationTestCase;
use App\Value\SenderInventoryFilter;
use App\Value\SenderReviewState;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;

final class GetSenderInventoryTest extends IntegrationTestCase
{
    /**
     * A caller with no readable teams must get nothing, without the query ever
     * reaching the database — an empty IN () list is a SQL error, and any
     * fallback that dropped the team predicate would leak another tenant's
     * senders.
     */
    #[Test]
    public function aCallerWithNoTeamsSeesNoSenders(): void
    {
        self::bootKernel();

        self::assertSame(
            [],
            $this->getService(GetSenderInventory::class)->forDomain(Uuid::uuid7()->toString(), []),
        );
    }

    #[Test]
    public function sendersAreReturnedWithTheirReviewStateResolved(): void
    {
        self::bootKernel();
        $fixtures = TestFixtures::fromContainer(self::getContainer());

        $persona = $fixtures->persona()
            ->emailPrefix('inventory-query')
            ->withKnownSender('203.0.113.81', totalMessages: 300, organization: 'Seznam')
            ->withKnownSender('203.0.113.82', totalMessages: 200, reviewState: SenderReviewState::Authorized)
            ->withKnownSender('203.0.113.83', totalMessages: 100, reviewState: SenderReviewState::NotAuthorized)
            ->build();
        assert(null !== $persona->domain);

        $senders = $this->getService(GetSenderInventory::class)->forDomain(
            $persona->domain->id->toString(),
            [$persona->team->id->toString()],
        );

        self::assertCount(3, $senders);
        self::assertSame(
            [SenderReviewState::NeedsReview, SenderReviewState::Authorized, SenderReviewState::NotAuthorized],
            array_map(static fn ($sender) => $sender->reviewState, $senders),
            'Ordered by volume, and each row carries the state its badge renders.',
        );
    }

    /**
     * The legacy filter value spans both non-authorized states; the two precise
     * ones must not.
     */
    #[Test]
    public function eachFilterReturnsExactlyTheStateItNames(): void
    {
        self::bootKernel();
        $fixtures = TestFixtures::fromContainer(self::getContainer());
        $query = $this->getService(GetSenderInventory::class);

        $persona = $fixtures->persona()
            ->emailPrefix('inventory-query-filter')
            ->withKnownSender('203.0.113.91', reviewState: SenderReviewState::NeedsReview)
            ->withKnownSender('203.0.113.92', reviewState: SenderReviewState::Authorized)
            ->withKnownSender('203.0.113.93', reviewState: SenderReviewState::NotAuthorized)
            ->build();
        assert(null !== $persona->domain);

        $domainId = $persona->domain->id->toString();
        $teamIds = [$persona->team->id->toString()];

        $states = static fn (?SenderInventoryFilter $filter): array => array_map(
            static fn ($sender) => $sender->reviewState,
            $query->forDomain($domainId, $teamIds, $filter),
        );

        self::assertSame([SenderReviewState::NeedsReview], $states(SenderInventoryFilter::NeedsReview));
        self::assertSame([SenderReviewState::Authorized], $states(SenderInventoryFilter::Authorized));
        self::assertSame([SenderReviewState::NotAuthorized], $states(SenderInventoryFilter::NotAuthorized));
        self::assertCount(2, $states(SenderInventoryFilter::Unauthorized));
        self::assertCount(3, $states(null));
    }
}
