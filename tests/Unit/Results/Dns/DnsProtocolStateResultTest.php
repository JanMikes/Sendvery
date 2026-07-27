<?php

declare(strict_types=1);

namespace App\Tests\Unit\Results\Dns;

use App\Results\Dns\DnsProtocolStateResult;
use App\Value\DnsCheckType;
use App\Value\ProtocolState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Turning a stored DNS check into a setup verdict. The Missing/Invalid
 * distinction is load-bearing: it decides whether the user is told to ADD a
 * record or to EDIT the one they have, and publishing a second SPF or DMARC
 * record instead of fixing the first breaks the domain outright.
 */
final class DnsProtocolStateResultTest extends TestCase
{
    #[Test]
    public function aValidCheckMeansTheRecordIsConfigured(): void
    {
        self::assertSame(
            ProtocolState::Configured,
            $this->stateResult(rawRecord: '10 mx1.example.net', isValid: true)->protocolState(),
        );
    }

    #[Test]
    public function noStoredRecordMeansThereIsNothingPublishedToFix(): void
    {
        self::assertSame(
            ProtocolState::Missing,
            $this->stateResult(rawRecord: null, isValid: false)->protocolState(),
        );
    }

    #[Test]
    public function aWhitespaceOnlyRecordCountsAsNothingPublished(): void
    {
        self::assertSame(
            ProtocolState::Missing,
            $this->stateResult(rawRecord: "  \n", isValid: false)->protocolState(),
        );
    }

    #[Test]
    public function aStoredButFailingRecordMeansTheUserHasSomethingToRepair(): void
    {
        self::assertSame(
            ProtocolState::Invalid,
            $this->stateResult(rawRecord: 'v=spf1 include:broken.example -all', isValid: false)->protocolState(),
        );
    }

    #[Test]
    public function itReadsARowFromTheDatabase(): void
    {
        $result = DnsProtocolStateResult::fromDatabaseRow([
            'check_type' => 'mx',
            'checked_at' => '2026-07-27 10:15:00',
            'raw_record' => '10 mx1.example.net',
            'is_valid' => true,
        ]);

        self::assertSame(DnsCheckType::Mx, $result->type);
        self::assertSame('2026-07-27 10:15:00', $result->checkedAt->format('Y-m-d H:i:s'));
        self::assertSame('10 mx1.example.net', $result->rawRecord);
        self::assertTrue($result->isValid);
    }

    /**
     * Postgres booleans reach a raw DBAL query as a native bool, as `1`/`0`, or
     * as `'t'`/`'f'` depending on driver and platform. Misreading any of these
     * would flip a healthy record to "missing" — the exact class of bug this
     * query was introduced to kill.
     */
    #[Test]
    #[DataProvider('booleanShapeProvider')]
    public function itUnderstandsEveryShapeAPostgresBooleanArrivesIn(bool|int|string $stored, bool $expected): void
    {
        $result = DnsProtocolStateResult::fromDatabaseRow([
            'check_type' => 'spf',
            'checked_at' => '2026-07-27 10:15:00',
            'raw_record' => 'v=spf1 -all',
            'is_valid' => $stored,
        ]);

        self::assertSame($expected, $result->isValid);
    }

    /**
     * @return iterable<string, array{0: bool|int|string, 1: bool}>
     */
    public static function booleanShapeProvider(): iterable
    {
        yield 'native true' => [true, true];
        yield 'native false' => [false, false];
        yield 'integer one' => [1, true];
        yield 'integer zero' => [0, false];
        yield 'postgres t' => ['t', true];
        yield 'postgres f' => ['f', false];
        yield 'string true' => ['true', true];
        yield 'string false' => ['false', false];
    }

    private function stateResult(?string $rawRecord, bool $isValid): DnsProtocolStateResult
    {
        return new DnsProtocolStateResult(
            type: DnsCheckType::Spf,
            checkedAt: new \DateTimeImmutable('2026-07-27 10:15:00'),
            rawRecord: $rawRecord,
            isValid: $isValid,
        );
    }
}
