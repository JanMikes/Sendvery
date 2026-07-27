<?php

declare(strict_types=1);

namespace App\Tests\Unit\Doctrine;

use App\Doctrine\PolicyOverrideReasonsType;
use App\Value\PolicyOverrideReason;
use App\Value\PolicyOverrideReasonType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use PHPUnit\Framework\TestCase;

final class PolicyOverrideReasonsTypeTest extends TestCase
{
    private PolicyOverrideReasonsType $type;

    private AbstractPlatform $platform;

    protected function setUp(): void
    {
        $this->type = new PolicyOverrideReasonsType();
        $this->platform = new PostgreSQLPlatform();
    }

    public function testReasonsSurviveAFullRoundTrip(): void
    {
        $reasons = [
            new PolicyOverrideReason(PolicyOverrideReasonType::LocalPolicy, 'arc=pass'),
            new PolicyOverrideReason(PolicyOverrideReasonType::Forwarded),
        ];

        $restored = $this->type->convertToPHPValue(
            $this->type->convertToDatabaseValue($reasons, $this->platform),
            $this->platform,
        );

        self::assertEquals($reasons, $restored, 'What was stored is what comes back — type and comment both');
    }

    public function testARecordWithNoReasonsStoresAnEmptyList(): void
    {
        self::assertSame('[]', $this->type->convertToDatabaseValue([], $this->platform));
        self::assertSame([], $this->type->convertToPHPValue('[]', $this->platform));
    }

    public function testAnEmptyColumnReadsAsNoReasons(): void
    {
        // Rows written before this column existed default to '[]', but a NULL
        // or blank must degrade to "nothing recorded", never to a hydration
        // failure that takes the whole DMARC record down with it.
        self::assertSame([], $this->type->convertToPHPValue(null, $this->platform));
        self::assertSame([], $this->type->convertToPHPValue('', $this->platform));
    }

    public function testStoredShapesTheReaderCannotUnderstandAreIgnored(): void
    {
        $reasons = $this->type->convertToPHPValue(
            '[{"type":"local_policy","comment":"arc=pass"},"not-an-object",{"comment":"no type"},{"type":42}]',
            $this->platform,
        );

        self::assertCount(1, $reasons, 'Unreadable entries are dropped, the readable one is kept');
        self::assertSame(PolicyOverrideReasonType::LocalPolicy, $reasons[0]->type);
    }

    public function testAJsonScalarInTheColumnReadsAsNoReasons(): void
    {
        self::assertSame([], $this->type->convertToPHPValue('"a string"', $this->platform));
    }

    public function testANonStringCommentIsDroppedRatherThanCoerced(): void
    {
        $reasons = $this->type->convertToPHPValue('[{"type":"other","comment":{"nested":"object"}}]', $this->platform);

        self::assertCount(1, $reasons);
        self::assertNull($reasons[0]->comment);
    }

    public function testOnlyRealReasonsAreWrittenToTheColumn(): void
    {
        // The property is typed, so this can only happen if something bypasses
        // it; persisting the junk would poison every later read of the row.
        self::assertSame('[]', $this->type->convertToDatabaseValue(['nonsense'], $this->platform));
        self::assertSame('[]', $this->type->convertToDatabaseValue('not a list', $this->platform));
    }
}
