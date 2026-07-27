<?php

declare(strict_types=1);

namespace App\Tests\Unit\Results;

use App\Results\DnsHealthOverviewResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DnsHealthOverviewResultTest extends TestCase
{
    #[Test]
    public function theLatestCheckVerdictKeepsItsThreeStatesWhateverTheDriverHandsBack(): void
    {
        // Postgres booleans come back as real bools on some pdo_pgsql builds and
        // as `'t'`/`'f'` on others. All three states have to survive that:
        // true = the record is valid now, false = the check ran and it is not,
        // and NULL = no check row exists — which must never collapse into false,
        // because "we have not looked" is not "it is broken".
        $result = DnsHealthOverviewResult::fromDatabaseRow($this->row([
            'spf_check_valid' => 'f',
            'dkim_check_valid' => 't',
            'dmarc_check_valid' => true,
            'mx_check_valid' => null,
        ]));

        self::assertFalse($result->spfCheckValid);
        self::assertTrue($result->dkimCheckValid);
        self::assertTrue($result->dmarcCheckValid);
        self::assertNull($result->mxCheckValid, 'No check row must stay null, not become false.');
    }

    #[Test]
    public function theCheckVerdictColumnsAreOptionalForLegacyCallers(): void
    {
        // Snapshot fixtures and older call sites build rows without the check
        // columns. They must degrade to "never checked" rather than blow up.
        $result = DnsHealthOverviewResult::fromDatabaseRow($this->row());

        self::assertNull($result->spfCheckValid);
        self::assertNull($result->dkimCheckValid);
        self::assertNull($result->dmarcCheckValid);
        self::assertNull($result->mxCheckValid);
        self::assertTrue($result->isSpfVerified(), 'The historical verification timestamps are untouched by this addition.');
    }

    /**
     * @param array<string, bool|string|null> $overrides
     *
     * @return array{
     *     domain_id: string,
     *     domain_name: string,
     *     spf_verified_at: string|null,
     *     dkim_verified_at: string|null,
     *     dmarc_verified_at: string|null,
     *     latest_snapshot_grade: string|null,
     *     latest_snapshot_score: int|string|null,
     *     latest_spf_score: int|string|null,
     *     latest_dkim_score: int|string|null,
     *     latest_dmarc_score: int|string|null,
     *     latest_mx_score: int|string|null,
     *     latest_checked_at: string|null,
     *     spf_check_valid?: bool|int|string|null,
     *     dkim_check_valid?: bool|int|string|null,
     *     dmarc_check_valid?: bool|int|string|null,
     *     mx_check_valid?: bool|int|string|null
     * }
     */
    private function row(array $overrides = []): array
    {
        /** @var array{domain_id: string, domain_name: string, spf_verified_at: string|null, dkim_verified_at: string|null, dmarc_verified_at: string|null, latest_snapshot_grade: string|null, latest_snapshot_score: int|string|null, latest_spf_score: int|string|null, latest_dkim_score: int|string|null, latest_dmarc_score: int|string|null, latest_mx_score: int|string|null, latest_checked_at: string|null, spf_check_valid?: bool|int|string|null, dkim_check_valid?: bool|int|string|null, dmarc_check_valid?: bool|int|string|null, mx_check_valid?: bool|int|string|null} $row */
        $row = array_merge([
            'domain_id' => 'domain-1',
            'domain_name' => 'example.com',
            'spf_verified_at' => '2026-03-15 10:00:00',
            'dkim_verified_at' => '2026-03-15 10:00:00',
            'dmarc_verified_at' => '2026-03-15 10:00:00',
            'latest_snapshot_grade' => 'A',
            'latest_snapshot_score' => '95',
            'latest_spf_score' => '100',
            'latest_dkim_score' => '100',
            'latest_dmarc_score' => '100',
            'latest_mx_score' => '95',
            'latest_checked_at' => '2026-03-16 03:00:00',
        ], $overrides);

        return $row;
    }
}
