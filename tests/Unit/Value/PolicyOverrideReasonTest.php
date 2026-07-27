<?php

declare(strict_types=1);

namespace App\Tests\Unit\Value;

use App\Value\PolicyOverrideReason;
use App\Value\PolicyOverrideReasonType;
use PHPUnit\Framework\TestCase;

final class PolicyOverrideReasonTest extends TestCase
{
    public function testKeepsTheReceiversCommentVerbatim(): void
    {
        $reason = new PolicyOverrideReason(PolicyOverrideReasonType::LocalPolicy, 'arc=pass');

        self::assertSame(PolicyOverrideReasonType::LocalPolicy, $reason->type);
        self::assertSame('arc=pass', $reason->comment, 'The comment is evidence and is stored as sent');
    }

    public function testAReasonWithoutACommentHasNone(): void
    {
        $reason = new PolicyOverrideReason(PolicyOverrideReasonType::Forwarded);

        self::assertNull($reason->comment);
    }

    public function testAnEmptyOrWhitespaceCommentCountsAsNoComment(): void
    {
        // Receivers emit `<comment></comment>` and `<comment> </comment>`; an
        // empty string would read downstream as "the receiver said something".
        self::assertNull(new PolicyOverrideReason(PolicyOverrideReasonType::Other, '')->comment);
        self::assertNull(new PolicyOverrideReason(PolicyOverrideReasonType::Other, "  \n ")->comment);
    }

    public function testSurroundingWhitespaceIsStrippedFromTheComment(): void
    {
        $reason = new PolicyOverrideReason(PolicyOverrideReasonType::LocalPolicy, "\n      arc=pass\n    ");

        self::assertSame('arc=pass', $reason->comment, 'Indented XML must not become part of the evidence');
    }

    public function testAnUnboundedCommentCannotBeStored(): void
    {
        $reason = new PolicyOverrideReason(PolicyOverrideReasonType::Other, str_repeat('x', 5_000));

        self::assertNotNull($reason->comment);
        self::assertSame(PolicyOverrideReason::MAX_COMMENT_LENGTH, mb_strlen($reason->comment));
        self::assertSame(str_repeat('x', PolicyOverrideReason::MAX_COMMENT_LENGTH), $reason->comment, 'Truncation is a hard cut, with no marker that could pass for reporter text');
    }

    public function testTruncationCountsCharactersNotBytes(): void
    {
        $reason = new PolicyOverrideReason(PolicyOverrideReasonType::Other, str_repeat('é', 400));

        self::assertNotNull($reason->comment);
        self::assertSame(PolicyOverrideReason::MAX_COMMENT_LENGTH, mb_strlen($reason->comment), 'A multibyte comment is cut on a character boundary, never mid-character');
    }
}
