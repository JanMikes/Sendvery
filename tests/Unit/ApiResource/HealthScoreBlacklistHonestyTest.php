<?php

declare(strict_types=1);

namespace App\Tests\Unit\ApiResource;

use App\ApiResource\HealthScoreResource;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The REST API must not report a blacklist verdict it never measured — and must
 * not report the WORST possible verdict for one either.
 *
 * `HealthScoreProvider` mapped the column with `(int) $row['blacklist_score']`
 * into a non-nullable int, so a NULL — "no check has run" — reached API
 * consumers as `0`. Zero is not a neutral placeholder here: on a 0-100 scale
 * where 100 means clean, it is the exact value meaning "listed on every DNSBL we
 * query". An integrator scripting against this endpoint would have read a
 * totally unmeasured domain as a catastrophically blacklisted one, and unlike a
 * web page there is no surrounding copy to correct the impression.
 *
 * This is the mechanical tell from CLAUDE.md — a non-nullable numeric field for
 * something measured that can have zero measurements — found in the read path
 * after the write path had already been fixed. Two further surfaces (the public
 * share page and the signed-in health page) carried the identical defect via
 * `DomainHealthSnapshotResult`.
 *
 * Asserted against the mapping rather than over HTTP because the API firewall is
 * stateless and cannot be reached with a session login. The mapping is where the
 * defect lived, and it now follows the same `fromDatabaseRow()` convention every
 * DTO in `src/Results/` uses.
 */
final class HealthScoreBlacklistHonestyTest extends TestCase
{
    #[Test]
    public function anUnmeasuredBlacklistStaysNullRatherThanBecomingZero(): void
    {
        $resource = HealthScoreResource::fromDatabaseRow($this->row(blacklistScore: null));

        self::assertNull(
            $resource->blacklistScore,
            'On a scale where 100 is clean, reporting 0 for an unrun check tells an API consumer the domain is listed everywhere. Absence has to stay absent.',
        );
    }

    #[Test]
    public function aMeasuredListingIsStillReportedAsZero(): void
    {
        $resource = HealthScoreResource::fromDatabaseRow($this->row(blacklistScore: 0));

        self::assertSame(
            0,
            $resource->blacklistScore,
            'A genuinely measured listing must survive the same code path that now preserves null, or the fix has traded one silence for another.',
        );
    }

    #[Test]
    public function aMeasuredCleanResultIsReportedAsOneHundred(): void
    {
        $resource = HealthScoreResource::fromDatabaseRow($this->row(blacklistScore: 100));

        self::assertSame(100, $resource->blacklistScore);
    }

    #[Test]
    public function theAlwaysMeasuredCategoriesAreUnaffected(): void
    {
        // Guards the edit itself: the four DNS scores are always measured, so
        // they must keep coercing exactly as before rather than acquiring a
        // null path they have no use for.
        $resource = HealthScoreResource::fromDatabaseRow($this->row(blacklistScore: null));

        self::assertSame(100, $resource->spfScore);
        self::assertSame(100, $resource->dkimScore);
        self::assertSame(100, $resource->dmarcScore);
        self::assertSame(95, $resource->mxScore);
        self::assertSame('A', $resource->grade);
    }

    /**
     * Values arrive from DBAL as strings, which is why the mapping casts at all.
     *
     * @return array{id: mixed, grade: mixed, score: mixed, spf_score: mixed, dkim_score: mixed, dmarc_score: mixed, mx_score: mixed, blacklist_score: mixed, checked_at: mixed}
     */
    private function row(?int $blacklistScore): array
    {
        return [
            'id' => '019fa566-9b01-71d2-bae8-d991cc2f5d40',
            'grade' => 'A',
            'score' => '95',
            'spf_score' => '100',
            'dkim_score' => '100',
            'dmarc_score' => '100',
            'mx_score' => '95',
            'blacklist_score' => null === $blacklistScore ? null : (string) $blacklistScore,
            'checked_at' => '2026-07-28 06:30:00',
        ];
    }
}
