<?php

declare(strict_types=1);

namespace App\Tests\Unit\Results;

use App\Results\DomainSendersAwaitingReview;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DomainSendersAwaitingReviewTest extends TestCase
{
    #[Test]
    public function fromDatabaseRowCarriesTheVolumeFiguresTheThresholdIsJudgedOn(): void
    {
        $domain = DomainSendersAwaitingReview::fromDatabaseRow([
            'domain_id' => '550e8400-e29b-41d4-a716-446655440000',
            'domain_name' => 'acme.example',
            'needs_review_count' => '4',
            'needs_review_messages' => '600',
            'largest_sender_messages' => '500',
            'distinct_name_count' => '4',
            'domain_messages' => '2400',
        ], ['Seznam', 'Mailchimp']);

        self::assertSame('550e8400-e29b-41d4-a716-446655440000', $domain->domainId);
        self::assertSame('acme.example', $domain->domainName);
        self::assertSame(4, $domain->needsReviewCount);
        self::assertSame(600, $domain->needsReviewMessages);
        self::assertSame(500, $domain->largestSenderMessages);
        self::assertSame(2400, $domain->domainMessages);
        self::assertSame(['Seznam', 'Mailchimp'], $domain->topSenderNames);
        self::assertSame(25.0, $domain->sharePercent());
        self::assertTrue($domain->hasMoreThanNamed());
        self::assertSame(2, $domain->unnamedCount());
    }

    #[Test]
    public function aBigUnreviewedSenderMakesTheDomainWorthEmailingAbout(): void
    {
        $domain = new DomainSendersAwaitingReview(
            domainId: 'd',
            domainName: 'acme.example',
            needsReviewCount: 1,
            needsReviewMessages: 500,
            largestSenderMessages: 500,
            domainMessages: 100000,
            topSenderNames: ['Seznam'],
            distinctNameCount: 1,
        );

        self::assertTrue($domain->isMaterial());
    }

    #[Test]
    public function aHandfulOfOneMessageSendersIsNotWorthEmailingAbout(): void
    {
        $domain = new DomainSendersAwaitingReview(
            domainId: 'd',
            domainName: 'acme.example',
            needsReviewCount: 3,
            needsReviewMessages: 3,
            largestSenderMessages: 1,
            domainMessages: 9000,
            topSenderNames: ['a', 'b', 'c'],
            distinctNameCount: 3,
        );

        self::assertFalse($domain->isMaterial());
        self::assertFalse($domain->hasMoreThanNamed());
        self::assertSame(0, $domain->unnamedCount());
    }
}
