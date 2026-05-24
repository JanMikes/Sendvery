<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value;

use App\Value\ReportsFilter;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;

final class ReportsFilterTest extends TestCase
{
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->clock = new MockClock(new \DateTimeImmutable('2026-05-23 12:00:00'));
    }

    public function testEmptyRequestProducesEmptyFilter(): void
    {
        $filter = ReportsFilter::fromRequest(new Request(), $this->clock);

        self::assertSame([], $filter->domainIds);
        self::assertSame([], $filter->reporterOrgs);
        self::assertNull($filter->passRateBand);
        self::assertNull($filter->dateRange);
        self::assertNull($filter->dateFrom);
        self::assertNull($filter->dateTo);
        self::assertNull($filter->search);
        self::assertFalse($filter->hasActiveFilters());
    }

    public function testDomainIdsAreValidatedAsUuids(): void
    {
        $validUuid = Uuid::uuid7()->toString();

        $request = new Request(['domain' => [$validUuid, 'not-a-uuid', '', '   ', $validUuid]]);
        $filter = ReportsFilter::fromRequest($request, $this->clock);

        self::assertSame([$validUuid, $validUuid], $filter->domainIds);
    }

    public function testDomainIdsNonStringEntriesAreFilteredOut(): void
    {
        $validUuid = Uuid::uuid7()->toString();

        $request = new Request(['domain' => [$validUuid, ['nested-array']]]);
        $filter = ReportsFilter::fromRequest($request, $this->clock);

        self::assertSame([$validUuid], $filter->domainIds);
    }

    public function testReporterOrgsAreTrimmedAndEmptyDropped(): void
    {
        $request = new Request(['reporter' => ['  google.com  ', '', 'yahoo.com', '   ']]);
        $filter = ReportsFilter::fromRequest($request, $this->clock);

        self::assertSame(['google.com', 'yahoo.com'], $filter->reporterOrgs);
    }

    public function testReporterOrgsNonStringEntriesAreFilteredOut(): void
    {
        $request = new Request(['reporter' => ['google.com', ['nested']]]);
        $filter = ReportsFilter::fromRequest($request, $this->clock);

        self::assertSame(['google.com'], $filter->reporterOrgs);
    }

    public function testPassRateBandAcceptsKnownValues(): void
    {
        foreach (['high', 'medium', 'low'] as $band) {
            $filter = ReportsFilter::fromRequest(new Request(['pass_rate' => $band]), $this->clock);
            self::assertSame($band, $filter->passRateBand, "band={$band}");
        }
    }

    public function testPassRateBandUnknownValueIsNull(): void
    {
        $filter = ReportsFilter::fromRequest(new Request(['pass_rate' => 'garbage']), $this->clock);

        self::assertNull($filter->passRateBand);
    }

    public function testDateRange7dProducesDateFromOnly(): void
    {
        $filter = ReportsFilter::fromRequest(new Request(['date_range' => '7d']), $this->clock);

        self::assertSame('7d', $filter->dateRange);
        self::assertNotNull($filter->dateFrom);
        self::assertSame('2026-05-16 12:00:00', $filter->dateFrom->format('Y-m-d H:i:s'));
        self::assertNull($filter->dateTo);
    }

    public function testDateRange30dProducesDateFromOnly(): void
    {
        $filter = ReportsFilter::fromRequest(new Request(['date_range' => '30d']), $this->clock);

        self::assertNotNull($filter->dateFrom);
        self::assertSame('2026-04-23', $filter->dateFrom->format('Y-m-d'));
        self::assertNull($filter->dateTo);
    }

    public function testDateRange90dProducesDateFromOnly(): void
    {
        $filter = ReportsFilter::fromRequest(new Request(['date_range' => '90d']), $this->clock);

        self::assertNotNull($filter->dateFrom);
        self::assertSame('2026-02-22', $filter->dateFrom->format('Y-m-d'));
        self::assertNull($filter->dateTo);
    }

    public function testDateRangeCustomParsesBothDates(): void
    {
        $filter = ReportsFilter::fromRequest(new Request([
            'date_range' => 'custom',
            'date_from' => '2026-01-01',
            'date_to' => '2026-02-15',
        ]), $this->clock);

        self::assertSame('custom', $filter->dateRange);
        self::assertNotNull($filter->dateFrom);
        self::assertNotNull($filter->dateTo);
        self::assertSame('2026-01-01', $filter->dateFrom->format('Y-m-d'));
        self::assertSame('2026-02-15', $filter->dateTo->format('Y-m-d'));
    }

    public function testDateRangeCustomSwapsReversedDates(): void
    {
        $filter = ReportsFilter::fromRequest(new Request([
            'date_range' => 'custom',
            'date_from' => '2026-02-15',
            'date_to' => '2026-01-01',
        ]), $this->clock);

        self::assertNotNull($filter->dateFrom);
        self::assertNotNull($filter->dateTo);
        self::assertSame('2026-01-01', $filter->dateFrom->format('Y-m-d'));
        self::assertSame('2026-02-15', $filter->dateTo->format('Y-m-d'));
    }

    public function testDateRangeCustomIgnoresInvalidDates(): void
    {
        $filter = ReportsFilter::fromRequest(new Request([
            'date_range' => 'custom',
            'date_from' => 'not-a-date',
            'date_to' => '',
        ]), $this->clock);

        self::assertSame('custom', $filter->dateRange);
        self::assertNull($filter->dateFrom);
        self::assertNull($filter->dateTo);
    }

    public function testDateRangeUnknownValueIsNull(): void
    {
        $filter = ReportsFilter::fromRequest(new Request(['date_range' => '6months']), $this->clock);

        self::assertNull($filter->dateRange);
        self::assertNull($filter->dateFrom);
        self::assertNull($filter->dateTo);
    }

    public function testSearchTrimsAndNullsEmpty(): void
    {
        $filter = ReportsFilter::fromRequest(new Request(['q' => '  google  ']), $this->clock);
        self::assertSame('google', $filter->search);

        $emptyFilter = ReportsFilter::fromRequest(new Request(['q' => '   ']), $this->clock);
        self::assertNull($emptyFilter->search);
    }

    public function testToQueryParamsOmitsEmptyValues(): void
    {
        $filter = new ReportsFilter([], [], null, null, null, null, null);

        self::assertSame([], $filter->toQueryParams());
    }

    public function testToQueryParamsIncludesAllSetValues(): void
    {
        $uuid = Uuid::uuid7()->toString();
        $filter = new ReportsFilter(
            domainIds: [$uuid],
            reporterOrgs: ['google.com'],
            passRateBand: 'high',
            dateRange: 'custom',
            dateFrom: new \DateTimeImmutable('2026-01-01'),
            dateTo: new \DateTimeImmutable('2026-02-01'),
            search: 'foo',
        );

        $params = $filter->toQueryParams();
        self::assertSame([$uuid], $params['domain']);
        self::assertSame(['google.com'], $params['reporter']);
        self::assertSame('high', $params['pass_rate']);
        self::assertSame('custom', $params['date_range']);
        self::assertSame('2026-01-01', $params['date_from']);
        self::assertSame('2026-02-01', $params['date_to']);
        self::assertSame('foo', $params['q']);
    }

    public function testToQueryParamsOmitsCustomDatesWhenDateRangeIsRelative(): void
    {
        $filter = new ReportsFilter(
            domainIds: [],
            reporterOrgs: [],
            passRateBand: null,
            dateRange: '7d',
            dateFrom: new \DateTimeImmutable('2026-05-16'),
            dateTo: null,
            search: null,
        );

        $params = $filter->toQueryParams();
        self::assertSame('7d', $params['date_range']);
        self::assertArrayNotHasKey('date_from', $params);
        self::assertArrayNotHasKey('date_to', $params);
    }

    public function testHasActiveFiltersIsTrueWhenAnyDimensionSet(): void
    {
        $uuid = Uuid::uuid7()->toString();

        self::assertTrue((new ReportsFilter([$uuid], [], null, null, null, null, null))->hasActiveFilters());
        self::assertTrue((new ReportsFilter([], ['x'], null, null, null, null, null))->hasActiveFilters());
        self::assertTrue((new ReportsFilter([], [], 'high', null, null, null, null))->hasActiveFilters());
        self::assertTrue((new ReportsFilter([], [], null, '7d', null, null, null))->hasActiveFilters());
        self::assertTrue((new ReportsFilter([], [], null, null, null, null, 'foo'))->hasActiveFilters());
        self::assertFalse((new ReportsFilter([], [], null, null, null, null, null))->hasActiveFilters());
    }

    public function testPassRateMinAndMax(): void
    {
        $high = new ReportsFilter([], [], 'high', null, null, null, null);
        self::assertSame(90.0, $high->passRateMin());
        self::assertNull($high->passRateMax());

        $medium = new ReportsFilter([], [], 'medium', null, null, null, null);
        self::assertSame(70.0, $medium->passRateMin());
        self::assertSame(89.99, $medium->passRateMax());

        $low = new ReportsFilter([], [], 'low', null, null, null, null);
        self::assertNull($low->passRateMin());
        self::assertSame(69.99, $low->passRateMax());

        $none = new ReportsFilter([], [], null, null, null, null, null);
        self::assertNull($none->passRateMin());
        self::assertNull($none->passRateMax());
    }

    public function testMailboxIdAcceptsValidUuid(): void
    {
        $uuid = Uuid::uuid7()->toString();
        $filter = ReportsFilter::fromRequest(new Request(['mailbox' => $uuid]), $this->clock);

        self::assertSame($uuid, $filter->mailboxId);
        self::assertTrue($filter->hasActiveFilters());
    }

    public function testMailboxIdRejectsNonUuidValue(): void
    {
        $filter = ReportsFilter::fromRequest(new Request(['mailbox' => 'not-a-uuid']), $this->clock);

        self::assertNull($filter->mailboxId);
    }

    public function testMailboxIdRejectsEmptyValue(): void
    {
        $filter = ReportsFilter::fromRequest(new Request(['mailbox' => '   ']), $this->clock);

        self::assertNull($filter->mailboxId);
    }

    public function testMailboxIdIsEmittedInQueryParams(): void
    {
        $uuid = Uuid::uuid7()->toString();
        $filter = new ReportsFilter(
            domainIds: [],
            reporterOrgs: [],
            passRateBand: null,
            dateRange: null,
            dateFrom: null,
            dateTo: null,
            search: null,
            mailboxId: $uuid,
        );

        self::assertSame($uuid, $filter->toQueryParams()['mailbox']);
    }

    public function testMailboxIdAlonePreservesHasActiveFilters(): void
    {
        $uuid = Uuid::uuid7()->toString();
        $filter = new ReportsFilter([], [], null, null, null, null, null, $uuid);

        self::assertTrue($filter->hasActiveFilters());
    }
}
